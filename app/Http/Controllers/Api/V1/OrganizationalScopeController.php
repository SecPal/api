<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationalScopeRequest;
use App\Http\Requests\UpdateOrganizationalScopeRequest;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use App\Rules\AssignableOrganizationalUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * OrganizationalScopeController handles CRUD operations for user organizational scope assignments.
 *
 * This controller manages which users have access to which organizational units
 * and at what access level. All operations require 'manage' access level on the
 * target organizational unit.
 *
 * Access Level Hierarchy:
 * - none (0): No access
 * - read (1): View only
 * - write (2): Can update
 * - manage (3): Full control including scope management
 */
class OrganizationalScopeController extends Controller
{
    private const SELF_SCOPE_ESCALATION_MESSAGE = 'You cannot create or expand your own organizational scope permissions.';

    private const SELF_SCOPE_UPDATE_MESSAGE = 'You cannot update your own organizational scope assignment for this organizational unit.';

    private const SELF_SCOPE_DELETION_MESSAGE = 'You cannot delete your own organizational scope assignment for this organizational unit.';

    /**
     * Transform a scope to API response format.
     *
     * @return array<string, mixed>
     */
    private function transformScope(UserInternalOrganizationalScope $scope, bool $includeUnit = false, bool $includeUser = false): array
    {
        $data = [
            'id' => $scope->id,
            'user_id' => $scope->user_id,
            'organizational_unit_id' => $scope->organizational_unit_id,
            'access_level' => $scope->access_level,
            'include_descendants' => $scope->include_descendants,
            // Leadership-based access control fields (ADR-009)
            'min_viewable_rank' => $scope->min_viewable_rank,
            'max_viewable_rank' => $scope->max_viewable_rank,
            'min_assignable_rank' => $scope->min_assignable_rank,
            'max_assignable_rank' => $scope->max_assignable_rank,
            'allow_self_access' => $scope->allow_self_access,
            'created_at' => \App\Support\ApiTimestamp::format($scope->created_at),
            'updated_at' => \App\Support\ApiTimestamp::format($scope->updated_at),
        ];

        if ($includeUnit && $scope->relationLoaded('organizationalUnit')) {
            $unit = $scope->organizationalUnit;
            if ($unit !== null) {
                $data['organizational_unit'] = [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'type' => $unit->type,
                ];
            }
        }

        if ($includeUser && $scope->relationLoaded('user')) {
            $user = $scope->user;
            $data['user'] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ];
        }

        return $data;
    }

    /**
     * Display a listing of scope assignments for an organizational unit.
     */
    public function index(OrganizationalUnit $organizational_unit): JsonResponse
    {
        $this->authorize('manageScopes', $organizational_unit);

        $scopes = UserInternalOrganizationalScope::where('organizational_unit_id', $organizational_unit->id)
            ->with('user:id,name,email')
            ->get();

        return response()->json([
            'data' => $scopes->map(fn ($scope) => $this->transformScope($scope, false, true)),
        ]);
    }

    /**
     * Store a newly created scope assignment.
     */
    public function store(StoreOrganizationalScopeRequest $request, OrganizationalUnit $organizational_unit): JsonResponse
    {
        $this->authorize('manageScopes', $organizational_unit);

        if (! $organizational_unit->is_assignable) {
            throw ValidationException::withMessages([
                'organizational_unit_id' => __(AssignableOrganizationalUnit::MESSAGE),
            ]);
        }

        /** @var array{user_id: string, access_level: string, include_descendants?: bool, min_viewable_rank?: int|null, max_viewable_rank?: int|null, min_assignable_rank?: int|null, max_assignable_rank?: int|null, allow_self_access?: bool} $validated */
        $validated = $request->validated();

        /** @var User $actor */
        $actor = $request->user();

        if ($this->isActorUserId($actor, $validated['user_id'])) {
            return response()->json([
                'message' => __(self::SELF_SCOPE_ESCALATION_MESSAGE),
            ], Response::HTTP_FORBIDDEN);
        }

        $scope = UserInternalOrganizationalScope::create([
            'user_id' => $validated['user_id'],
            'organizational_unit_id' => $organizational_unit->id,
            'access_level' => $validated['access_level'],
            'include_descendants' => $validated['include_descendants'] ?? true,
            'min_viewable_rank' => $validated['min_viewable_rank'] ?? null,
            'max_viewable_rank' => $validated['max_viewable_rank'] ?? null,
            'min_assignable_rank' => $validated['min_assignable_rank'] ?? null,
            'max_assignable_rank' => $validated['max_assignable_rank'] ?? null,
            'allow_self_access' => $validated['allow_self_access'] ?? false,
        ]);

        return response()->json([
            'data' => $this->transformScope($scope),
        ], Response::HTTP_CREATED);
    }

    /**
     * Update the specified scope assignment.
     */
    public function update(UpdateOrganizationalScopeRequest $request, OrganizationalUnit $organizational_unit, string $scope): JsonResponse
    {
        $this->authorize('manageScopes', $organizational_unit);

        /** @var User $actor */
        $actor = $request->user();

        // Load scope manually since route model binding doesn't auto-resolve nested models
        $scopeModel = UserInternalOrganizationalScope::find($scope);

        if ($scopeModel === null) {
            return response()->json([
                'message' => __('Scope not found'),
            ], 404);
        }

        // Verify scope belongs to this unit
        if ($scopeModel->organizational_unit_id !== $organizational_unit->id) {
            return response()->json([
                'message' => __('Scope not found for this organizational unit'),
            ], 404);
        }
        /** @var array{access_level?: string, include_descendants?: bool, min_viewable_rank?: int|null, max_viewable_rank?: int|null, min_assignable_rank?: int|null, max_assignable_rank?: int|null, allow_self_access?: bool} $validated */
        $validated = $request->validated();

        $selfUpdateResponse = $this->preventSelfScopeUpdate($actor, $scopeModel);

        if ($selfUpdateResponse !== null) {
            return $selfUpdateResponse;
        }

        if (! $organizational_unit->is_assignable && $this->scopeUpdateExpandsEntitlement($scopeModel, $validated)) {
            throw ValidationException::withMessages([
                'organizational_unit_id' => __(AssignableOrganizationalUnit::MESSAGE),
            ]);
        }

        if (isset($validated['access_level'])) {
            $scopeModel->access_level = $validated['access_level'];
        }

        if (isset($validated['include_descendants'])) {
            $scopeModel->include_descendants = $validated['include_descendants'];
        }

        // Leadership-based access control fields (ADR-009)
        if (array_key_exists('min_viewable_rank', $validated)) {
            $scopeModel->min_viewable_rank = $validated['min_viewable_rank'];
        }

        if (array_key_exists('max_viewable_rank', $validated)) {
            $scopeModel->max_viewable_rank = $validated['max_viewable_rank'];
        }

        if (array_key_exists('min_assignable_rank', $validated)) {
            $scopeModel->min_assignable_rank = $validated['min_assignable_rank'];
        }

        if (array_key_exists('max_assignable_rank', $validated)) {
            $scopeModel->max_assignable_rank = $validated['max_assignable_rank'];
        }

        if (isset($validated['allow_self_access'])) {
            $scopeModel->allow_self_access = $validated['allow_self_access'];
        }

        $scopeModel->save();

        return response()->json([
            'data' => $this->transformScope($scopeModel),
        ]);
    }

    /**
     * Determine whether an update grants access not covered by the current scope.
     *
     * @param  array{access_level?: string, include_descendants?: bool, min_viewable_rank?: int|null, max_viewable_rank?: int|null, min_assignable_rank?: int|null, max_assignable_rank?: int|null, allow_self_access?: bool}  $validated
     */
    private function scopeUpdateExpandsEntitlement(UserInternalOrganizationalScope $scope, array $validated): bool
    {
        $updatedScope = clone $scope;
        $updatedScope->fill($validated);

        if ($updatedScope->getAccessLevelValue() > $scope->getAccessLevelValue()) {
            return true;
        }

        if (! $scope->include_descendants && $updatedScope->include_descendants) {
            return true;
        }

        if (! $scope->allow_self_access && $updatedScope->allow_self_access) {
            return true;
        }

        for ($managementLevel = 0; $managementLevel <= 255; $managementLevel++) {
            if (
                (! $scope->canViewManagementLevel($managementLevel) && $updatedScope->canViewManagementLevel($managementLevel))
                || (! $scope->canAssignManagementLevel($managementLevel) && $updatedScope->canAssignManagementLevel($managementLevel))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the specified scope assignment.
     */
    public function destroy(OrganizationalUnit $organizational_unit, string $scope): JsonResponse|Response
    {
        $this->authorize('manageScopes', $organizational_unit);

        /** @var User $actor */
        $actor = request()->user();

        // Load scope manually since route model binding doesn't auto-resolve nested models
        $scopeModel = UserInternalOrganizationalScope::find($scope);

        if ($scopeModel === null) {
            return response()->json([
                'message' => __('Scope not found'),
            ], 404);
        }

        // Verify scope belongs to this unit
        if ($scopeModel->organizational_unit_id !== $organizational_unit->id) {
            return response()->json([
                'message' => __('Scope not found for this organizational unit'),
            ], 404);
        }

        $selfDeletionResponse = $this->preventSelfScopeDeletion($actor, $scopeModel);

        if ($selfDeletionResponse !== null) {
            return $selfDeletionResponse;
        }

        $scopeModel->delete();

        return response()->noContent();
    }

    /**
     * Get the authenticated user's organizational scope assignments.
     */
    public function myScopes(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        $scopes = $user->organizationalScopes()
            ->with('organizationalUnit:id,name,type')
            ->get()
            ->filter(fn (UserInternalOrganizationalScope $scope): bool => $scope->organizationalUnit !== null)
            ->values();

        return response()->json([
            'data' => $scopes->map(fn ($scope) => $this->transformScope($scope, true)),
        ]);
    }

    /**
     * Under the manage-only model, users may not mutate their own direct scope
     * assignments on the unit they are currently managing.
     */
    private function preventSelfScopeUpdate(User $actor, UserInternalOrganizationalScope $scopeModel): ?JsonResponse
    {
        if (! $this->isActorScope($actor, $scopeModel)) {
            return null;
        }

        return response()->json([
            'message' => __(self::SELF_SCOPE_UPDATE_MESSAGE),
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * Under the manage-only model, users may not delete their own direct scope
     * assignments on the unit they are currently managing.
     */
    private function preventSelfScopeDeletion(User $actor, UserInternalOrganizationalScope $scopeModel): ?JsonResponse
    {
        if (! $this->isActorScope($actor, $scopeModel)) {
            return null;
        }

        return response()->json([
            'message' => __(self::SELF_SCOPE_DELETION_MESSAGE),
        ], Response::HTTP_FORBIDDEN);
    }

    private function isActorScope(User $actor, UserInternalOrganizationalScope $scopeModel): bool
    {
        return $this->isActorUserId($actor, $scopeModel->user_id);
    }

    private function isActorUserId(User $actor, string $userId): bool
    {
        return strcasecmp($userId, $actor->id) === 0;
    }
}
