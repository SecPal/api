<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignUserPermissionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Manages direct permission assignments to users.
 *
 * Direct permissions bypass roles and are assigned individually.
 * Supports temporal constraints (valid_from, valid_until) for time-limited access.
 */
class UserPermissionController extends Controller
{
    /**
     * List all user permissions (via_roles + direct + all).
     *
     * Returns permissions grouped by source:
     * - via_roles: Permissions inherited from assigned roles
     * - direct: Permissions assigned directly to user
     * - all: Combined deduplicated list
     *
     * Authorization: User can view own, Admin can view all
     */
    public function index(User $user): JsonResponse
    {
        $this->ensureSameTenant($user);
        Gate::authorize('viewPermissions', $user);

        // Get all permissions (via roles + direct)
        $allPermissions = $user->getAllPermissions()->pluck('name');

        // Get permissions via roles
        $rolesWithPermissions = $user->roles->flatMap(function ($role) {
            /** @var \Spatie\Permission\Models\Role $role */
            return $role->permissions->map(function ($permission) use ($role) {
                /** @var \Spatie\Permission\Models\Permission $permission */
                return [
                    'name' => $permission->name,
                    'role' => $role->name,
                ];
            });
        });

        // Get direct permissions with pivot data
        $directPermissions = DB::table('model_has_permissions')
            ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
            ->where('model_has_permissions.model_type', $user->getMorphClass())
            ->where('model_has_permissions.model_id', $user->getKey())
            ->select([
                'permissions.name',
                'model_has_permissions.valid_from',
                'model_has_permissions.valid_until',
                'model_has_permissions.created_at as assigned_at',
                'model_has_permissions.assigned_by',
                'model_has_permissions.reason',
            ])
            ->get()
            ->map(function ($row) {
                return (array) $row;
            });

        return response()->json([
            'data' => [
                'via_roles' => $rolesWithPermissions,
                'direct' => $directPermissions,
                'all' => $allPermissions->unique()->values(),
            ],
        ]);
    }

    /**
     * Assign direct permission(s) to user.
     *
     * Supports bulk assignment and optional temporal constraints.
     * Authorization: Admin only
     */
    public function store(AssignUserPermissionRequest $request, User $user): JsonResponse
    {
        $this->ensureSameTenant($user);
        Gate::authorize('assignPermission', $user);

        /** @var array<int, string> $permissions */
        $permissions = $request->validated('permissions');
        $assignedPermissions = [];

        foreach ($permissions as $permissionName) {
            /** @var \Spatie\Permission\Models\Permission $permission */
            $permission = \Spatie\Permission\Models\Permission::findByName((string) $permissionName, 'sanctum');

            // Build pivot data array with temporal constraints
            $pivotData = [
                'valid_from' => $request->validated('valid_from'),
                'valid_until' => $request->validated('valid_until'),
                'assigned_by' => Auth::id(),
                'reason' => $request->validated('reason'),
            ];

            // Insert directly into pivot table with temporal data
            // Spatie's givePermissionTo doesn't support pivot attributes
            $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
            $teamId = $registrar->getPermissionsTeamId();

            DB::table('model_has_permissions')->updateOrInsert(
                [
                    'permission_id' => $permission->id,
                    'model_type' => $user->getMorphClass(),
                    'model_id' => $user->getKey(),
                    'tenant_id' => $teamId,
                ],
                array_merge($pivotData, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );

            $assignedPermissions[] = [
                'name' => $permission->name,
                'valid_from' => $pivotData['valid_from'] ?? null,
                'valid_until' => $pivotData['valid_until'] ?? null,
            ];
        }

        // Clear permission cache
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'message' => __('Permissions assigned successfully'),
            'data' => $assignedPermissions,
        ], 201);
    }

    /**
     * Revoke direct permission from user.
     *
     * Only removes direct assignment. Role-based permissions remain.
     * Authorization: Admin only
     */
    public function destroy(User $user, string $permission): JsonResponse
    {
        $this->ensureSameTenant($user);
        Gate::authorize('revokePermission', $user);

        // Check if user has this permission directly
        if (! $user->hasDirectPermission($permission)) {
            return response()->json([
                'message' => __('User does not have this permission directly assigned'),
            ], 404);
        }

        $user->revokePermissionTo($permission);

        // Clear permission cache
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'message' => __('Permission revoked successfully'),
        ]);
    }

    /**
     * List only direct permissions (excludes via_roles).
     *
     * Shows temporal constraints if present.
     * Authorization: User can view own, Admin can view all
     */
    public function direct(User $user): JsonResponse
    {
        $this->ensureSameTenant($user);
        Gate::authorize('viewPermissions', $user);

        // Get direct permissions with pivot data
        $directPermissions = DB::table('model_has_permissions')
            ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
            ->where('model_has_permissions.model_type', $user->getMorphClass())
            ->where('model_has_permissions.model_id', $user->getKey())
            ->select([
                'permissions.name',
                'model_has_permissions.valid_from',
                'model_has_permissions.valid_until',
                'model_has_permissions.created_at as assigned_at',
                'model_has_permissions.assigned_by',
                'model_has_permissions.reason',
            ])
            ->get()
            ->map(function ($row) {
                return (array) $row;
            });

        return response()->json([
            'data' => [
                'direct' => $directPermissions,
            ],
        ]);
    }

    /**
     * Reject cross-tenant target users with a fail-closed 404.
     */
    private function ensureSameTenant(User $targetUser): void
    {
        /** @var User $authUser */
        $authUser = Auth::user();

        if (! $authUser->sharesTenantWith($targetUser)) {
            throw (new ModelNotFoundException)->setModel(User::class, [$targetUser->getKey()]);
        }
    }
}
