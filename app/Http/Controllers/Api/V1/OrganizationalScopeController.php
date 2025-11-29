<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
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

/**
 * OrganizationalScopeController handles CRUD operations for user organizational scope assignments.
 *
 * This controller manages which users have access to which organizational units
 * and at what access level. All operations require 'admin' access level on the
 * target organizational unit.
 *
 * Access Level Hierarchy:
 * - none (0): No access
 * - read (1): View only
 * - write (2): Can update
 * - manage (3): Can create children and manage structure
 * - admin (4): Full control including scope management
 */
class OrganizationalScopeController extends Controller
{
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
            'created_at' => $scope->created_at->toIso8601String(),
            'updated_at' => $scope->updated_at->toIso8601String(),
        ];

        if ($includeUnit && $scope->relationLoaded('organizationalUnit')) {
            $unit = $scope->organizationalUnit;
            $data['organizational_unit'] = [
                'id' => $unit->id,
                'name' => $unit->name,
                'type' => $unit->type,
            ];
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

        /** @var array{user_id: string, access_level: string, include_descendants?: bool} $validated */
        $validated = $request->validated();

        $scope = UserInternalOrganizationalScope::create([
            'user_id' => $validated['user_id'],
            'organizational_unit_id' => $organizational_unit->id,
            'access_level' => $validated['access_level'],
            'include_descendants' => $validated['include_descendants'] ?? true,
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
        // Load scope manually since route model binding doesn't auto-resolve nested models
        $scopeModel = UserInternalOrganizationalScope::find($scope);

        if ($scopeModel === null) {
            return response()->json([
                'error' => 'Not found',
                'message' => 'Scope not found',
            ], 404);
        }

        // Verify scope belongs to this unit
        if ($scopeModel->organizational_unit_id !== $organizational_unit->id) {
            return response()->json([
                'error' => 'Not found',
                'message' => 'Scope not found for this organizational unit',
            ], 404);
        }

        $this->authorize('manageScopes', $organizational_unit);

        /** @var array{access_level?: string, include_descendants?: bool} $validated */
        $validated = $request->validated();

        if (isset($validated['access_level'])) {
            $scopeModel->access_level = $validated['access_level'];
        }

        if (isset($validated['include_descendants'])) {
            $scopeModel->include_descendants = $validated['include_descendants'];
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
        // Load scope manually since route model binding doesn't auto-resolve nested models
        $scopeModel = UserInternalOrganizationalScope::find($scope);

        if ($scopeModel === null) {
            return response()->json([
                'error' => 'Not found',
                'message' => 'Scope not found',
            ], 404);
        }

        // Verify scope belongs to this unit
        if ($scopeModel->organizational_unit_id !== $organizational_unit->id) {
            return response()->json([
                'error' => 'Not found',
                'message' => 'Scope not found for this organizational unit',
            ], 404);
        }

        $this->authorize('manageScopes', $organizational_unit);

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
            ->get();

        return response()->json([
            'data' => $scopes->map(fn ($scope) => $this->transformScope($scope, true)),
        ]);
    }
}
