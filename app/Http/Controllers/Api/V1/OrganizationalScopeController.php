<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationalScopeRequest;
use App\Http\Requests\UpdateOrganizationalScopeRequest;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

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
    private const SELF_SCOPE_LOCKOUT_MESSAGE = 'You cannot remove your own last scope-management access for this organizational unit.';

    private const SELF_SCOPE_ESCALATION_MESSAGE = 'You cannot create or expand your own organizational scope permissions.';

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

        /** @var array{user_id: string, access_level: string, include_descendants?: bool, min_viewable_rank?: int|null, max_viewable_rank?: int|null, min_assignable_rank?: int|null, max_assignable_rank?: int|null, allow_self_access?: bool} $validated */
        $validated = $request->validated();

        /** @var User $actor */
        $actor = $request->user();

        if ($validated['user_id'] === $actor->id) {
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

        $lockoutResponse = $this->preventSelfScopeManagementLockout(
            $actor,
            $organizational_unit,
            $scopeModel,
            $validated,
        );

        if ($lockoutResponse !== null) {
            return $lockoutResponse;
        }

        $selfExpansionResponse = $this->preventSelfScopeExpansion($actor, $scopeModel, $validated);

        if ($selfExpansionResponse !== null) {
            return $selfExpansionResponse;
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

        $lockoutResponse = $this->preventSelfScopeManagementLockout(
            $actor,
            $organizational_unit,
            $scopeModel,
            deleteScope: true,
        );

        if ($lockoutResponse !== null) {
            return $lockoutResponse;
        }

        $scopeModel->delete();

        return response()->noContent();
    }

    /**
     * Prevent a user from removing their own last scope-management path on the unit.
     *
     * The access check uses {@see User::hasAccessToUnit()} with a caller-supplied in-memory
     * scope collection so the post-change state can be simulated without mutating data.
     * This differs from the removed controller-local helper which used `value('ancestor_id')`
     * to pick a single DB-ordered ancestor scope.  Evaluating all applicable scopes is the
     * correct behaviour: if the user retains manage access through any applicable scope
     * (direct or inherited), they are not locked out.  Resolves the non-determinism in the
     * previous helper and aligns with the shared direct-scope-first semantics of
     * {@see User::resolveApplicableOrganizationalScopesForUnit()} (refs api#982).
     *
     * @param  array<string, mixed>  $pendingAttributes
     */
    private function preventSelfScopeManagementLockout(
        User $actor,
        OrganizationalUnit $organizationalUnit,
        UserInternalOrganizationalScope $scopeModel,
        array $pendingAttributes = [],
        bool $deleteScope = false,
    ): ?JsonResponse {
        if ($scopeModel->user_id !== $actor->id) {
            return null;
        }

        /** @var Collection<int, UserInternalOrganizationalScope> $scopes */
        $scopes = $actor->organizationalScopes()->get()->values();

        $simulatedScopes = $scopes
            ->reject(fn (UserInternalOrganizationalScope $scope) => $scope->id === $scopeModel->id)
            ->values();

        if (! $deleteScope) {
            $simulatedScope = clone $scopeModel;

            foreach ($pendingAttributes as $attribute => $value) {
                $simulatedScope->{$attribute} = $value;
            }

            $simulatedScopes->push($simulatedScope);
        }

        if ($actor->hasAccessToUnit($organizationalUnit, 'manage', $simulatedScopes)) {
            return null;
        }

        return response()->json([
            'message' => __(self::SELF_SCOPE_LOCKOUT_MESSAGE),
        ], Response::HTTP_FORBIDDEN);
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
     * @param  array<string, mixed>  $pendingAttributes
     */
    private function preventSelfScopeExpansion(User $actor, UserInternalOrganizationalScope $scopeModel, array $pendingAttributes): ?JsonResponse
    {
        if ($scopeModel->user_id !== $actor->id) {
            return null;
        }

        $organizationalUnit = $scopeModel->organizationalUnit;

        if ($organizationalUnit === null) {
            return null;
        }

        /** @var Collection<int, UserInternalOrganizationalScope> $currentScopes */
        $currentScopes = $actor->organizationalScopes()->get()->values();

        $simulatedScopes = $currentScopes
            ->reject(fn (UserInternalOrganizationalScope $scope) => $scope->id === $scopeModel->id)
            ->values();

        $simulatedScope = clone $scopeModel;

        foreach ($pendingAttributes as $attribute => $value) {
            $simulatedScope->{$attribute} = $value;
        }

        $simulatedScopes->push($simulatedScope);

        if (! $this->effectiveAuthorizationExpands($actor, $scopeModel, $simulatedScope, $currentScopes, $simulatedScopes)) {
            return null;
        }

        return response()->json([
            'message' => __(self::SELF_SCOPE_ESCALATION_MESSAGE),
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * @param  Collection<int, UserInternalOrganizationalScope>  $currentScopes
     * @param  Collection<int, UserInternalOrganizationalScope>  $simulatedScopes
     */
    private function effectiveAuthorizationExpands(
        User $actor,
        UserInternalOrganizationalScope $currentScope,
        UserInternalOrganizationalScope $simulatedScope,
        Collection $currentScopes,
        Collection $simulatedScopes,
    ): bool {
        foreach ($this->affectedUnitsForSelfScopeChange($currentScope, $simulatedScope) as $organizationalUnit) {
            foreach (['read', 'write', 'manage'] as $minimumAccessLevel) {
                if (
                    $actor->hasAccessToUnit($organizationalUnit, $minimumAccessLevel, $simulatedScopes)
                    && ! $actor->hasAccessToUnit($organizationalUnit, $minimumAccessLevel, $currentScopes)
                ) {
                    return true;
                }
            }

            $currentViewScopes = $this->applicableScopesWithMinimumAccessLevel($actor, $organizationalUnit, $currentScopes, 'read');
            $simulatedViewScopes = $this->applicableScopesWithMinimumAccessLevel($actor, $organizationalUnit, $simulatedScopes, 'read');
            if (
                $simulatedViewScopes->contains(fn (UserInternalOrganizationalScope $scope): bool => $scope->allow_self_access)
                && ! $currentViewScopes->contains(fn (UserInternalOrganizationalScope $scope): bool => $scope->allow_self_access)
            ) {
                return true;
            }

            if ($this->managementLevelAuthorizationExpands($currentViewScopes, $simulatedViewScopes, assignable: false)) {
                return true;
            }

            if ($this->managementLevelAuthorizationExpands(
                $this->applicableScopesWithMinimumAccessLevel($actor, $organizationalUnit, $currentScopes, 'write'),
                $this->applicableScopesWithMinimumAccessLevel($actor, $organizationalUnit, $simulatedScopes, 'write'),
                assignable: true,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, OrganizationalUnit>
     */
    private function affectedUnitsForSelfScopeChange(
        UserInternalOrganizationalScope $currentScope,
        UserInternalOrganizationalScope $simulatedScope,
    ): Collection {
        $organizationalUnit = $currentScope->organizationalUnit;

        if ($organizationalUnit === null) {
            /** @var Collection<int, OrganizationalUnit> $emptyUnits */
            $emptyUnits = collect();

            return $emptyUnits;
        }

        /** @var Collection<int, OrganizationalUnit> $affectedUnits */
        $affectedUnits = collect([$organizationalUnit]);

        if ($currentScope->include_descendants || $simulatedScope->include_descendants) {
            /** @var Collection<int, OrganizationalUnit> $descendants */
            $descendants = $organizationalUnit->descendants()->get();

            $affectedUnits = $affectedUnits
                ->merge($descendants)
                ->unique('id')
                ->values();
        }

        return $affectedUnits;
    }

    /**
     * @param  Collection<int, UserInternalOrganizationalScope>  $scopes
     * @return Collection<int, UserInternalOrganizationalScope>
     */
    private function applicableScopesWithMinimumAccessLevel(
        User $actor,
        OrganizationalUnit $organizationalUnit,
        Collection $scopes,
        string $minimumAccessLevel,
    ): Collection {
        return $actor->getApplicableOrganizationalScopesForUnitUsingScopes($organizationalUnit, $scopes)
            ->filter(fn (UserInternalOrganizationalScope $scope): bool => $scope->hasMinimumAccessLevel($minimumAccessLevel))
            ->values();
    }

    /**
     * @param  Collection<int, UserInternalOrganizationalScope>  $currentScopes
     * @param  Collection<int, UserInternalOrganizationalScope>  $simulatedScopes
     */
    private function managementLevelAuthorizationExpands(
        Collection $currentScopes,
        Collection $simulatedScopes,
        bool $assignable,
    ): bool {
        foreach (range(0, 255) as $managementLevel) {
            $isNewlyAuthorized = $simulatedScopes->contains(
                fn (UserInternalOrganizationalScope $scope): bool => $assignable
                    ? $scope->canAssignManagementLevel($managementLevel)
                    : $scope->canViewManagementLevel($managementLevel)
            );

            if (! $isNewlyAuthorized) {
                continue;
            }

            $wasPreviouslyAuthorized = $currentScopes->contains(
                fn (UserInternalOrganizationalScope $scope): bool => $assignable
                    ? $scope->canAssignManagementLevel($managementLevel)
                    : $scope->canViewManagementLevel($managementLevel)
            );

            if (! $wasPreviouslyAuthorized) {
                return true;
            }
        }

        return false;
    }
}
