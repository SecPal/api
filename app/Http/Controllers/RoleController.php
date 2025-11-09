<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers;

use App\Http\Requests\AssignRoleRequest;
use App\Http\Requests\ExtendRoleRequest;
use App\Models\RoleAssignmentLog;
use App\Models\TemporalRoleUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * RoleController handles temporal role assignment API endpoints.
 *
 * All endpoints require:
 * - Sanctum authentication (auth:sanctum)
 * - Appropriate permissions (role.assign, role.read, role.revoke)
 */
class RoleController extends Controller
{
    /**
     * Assign a role to a user with temporal parameters.
     *
     * POST /v1/users/{user}/roles
     */
    public function store(AssignRoleRequest $request, int $user): JsonResponse
    {
        $user = User::findOrFail($user);
        $roleName = $request->string('role')->toString();
        $role = Role::where('name', $roleName)->firstOrFail();

        $validFrom = $request->has('valid_from')
            ? Carbon::parse($request->string('valid_from')->toString())
            : now();
        $validUntil = $request->has('valid_until')
            ? Carbon::parse($request->string('valid_until')->toString())
            : null;

        /** @var \App\Models\User $authUser */
        $authUser = $request->user();
        /** @var int|null $tenantId */
        $tenantId = app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId();

        DB::transaction(function () use ($user, $role, $validFrom, $validUntil, $request, $tenantId, $authUser) {
            // Direct database insert to bypass Spatie's relationship methods:
            // We require additional temporal and audit fields (valid_from, valid_until, auto_revoke, assigned_by, reason)
            // in the model_has_roles table, which are not supported by Spatie's built-in relationship methods.
            // This approach enables temporal role assignments and auditing, at the cost of bypassing Spatie's API.
            // Future maintainers: be aware that changes to Spatie's internals or upgrades may require review of this logic.
            DB::table('model_has_roles')->insert([
                'model_type' => get_class($user),
                'model_id' => $user->id,
                'role_id' => $role->id,
                'tenant_id' => $tenantId,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'auto_revoke' => $request->boolean('auto_revoke', true),
                'assigned_by' => $authUser->id,
                'reason' => $request->string('reason', '')->toString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Clear relationship cache
            $user->unsetRelation('roles');

            // Log assignment
            RoleAssignmentLog::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'action' => 'assigned',
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'assigned_by' => $authUser->id,
                'reason' => $request->string('reason', '')->toString(),
            ]);
        });

        return response()->json([
            'user_id' => $user->id,
            'role' => $role->name,
            'valid_from' => $validFrom->toIso8601String(),
            'valid_until' => $validUntil?->toIso8601String(),
            'auto_revoke' => $request->boolean('auto_revoke', true),
            'reason' => $request->string('reason', '')->toString(),
        ], Response::HTTP_CREATED);
    }

    /**
     * List all roles for a user with their expiration status.
     */
    public function index(Request $request, int $user): JsonResponse
    {
        /** @var int|null $tenantId */
        $tenantId = app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId();

        $roleAssignments = TemporalRoleUser::where('model_id', $user)
            ->where('model_type', User::class)
            ->where('tenant_id', $tenantId)
            ->get();

        // Eager load all roles needed for the assignments in a single query
        $roleIds = $roleAssignments->pluck('role_id')->unique()->all();
        $rolesById = Role::whereIn('id', $roleIds)->get()->keyBy('id');

        $roles = $roleAssignments->map(function ($assignment) use ($rolesById) {
            $now = now();
            $isActive = (! $assignment->valid_from || $assignment->valid_from->lte($now))
                && (! $assignment->valid_until || $assignment->valid_until->gte($now));

            // Get role name from pre-fetched roles
            $role = $rolesById->get($assignment->role_id);
            $roleName = $role instanceof Role ? $role->name : 'unknown';

            return [
                'role' => $roleName,
                'valid_from' => $assignment->valid_from?->toIso8601String(),
                'valid_until' => $assignment->valid_until?->toIso8601String(),
                'is_active' => $isActive,
                'is_expired' => $assignment->valid_until && $assignment->valid_until->lt($now),
                'auto_revoke' => $assignment->auto_revoke,
                'reason' => $assignment->reason,
            ];
        });

        return response()->json(['roles' => $roles]);
    }

    /**
     * Revoke a role from a user.
     *
     * DELETE /v1/users/{user}/roles/{role}
     */
    public function destroy(Request $request, int $user, string $roleName): JsonResponse|Response
    {
        $user = User::findOrFail($user);
        $role = Role::where('name', $roleName)->firstOrFail();

        /** @var \App\Models\User $authUser */
        $authUser = $request->user();
        /** @var int|null $tenantId */
        $tenantId = app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId();

        // Check if role is assigned
        $assignment = TemporalRoleUser::where('model_id', $user->id)
            ->where('model_type', User::class)
            ->where('role_id', $role->id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $assignment) {
            return response()->json([
                'error' => 'Role not assigned to user',
            ], Response::HTTP_NOT_FOUND);
        }

        DB::transaction(function () use ($user, $role, $assignment, $authUser) {
            // Log revocation
            RoleAssignmentLog::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'action' => 'revoked',
                'valid_from' => $assignment->valid_from,
                'valid_until' => $assignment->valid_until,
                'assigned_by' => $authUser->id,
                'reason' => 'Manual revocation',
            ]);

            // Remove role
            $user->removeRole($role->name);
        });

        return response()->noContent();
    }

    /**
     * Extend the expiration date of a role assignment.
     *
     * PATCH /v1/users/{user}/roles/{role}/extend
     */
    public function extend(ExtendRoleRequest $request, int $user, string $roleName): JsonResponse
    {
        $user = User::findOrFail($user);
        $role = Role::where('name', $roleName)->firstOrFail();

        /** @var \App\Models\User $authUser */
        $authUser = $request->user();
        /** @var int|null $tenantId */
        $tenantId = app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId();

        // Find assignment
        $assignment = TemporalRoleUser::where('model_id', $user->id)
            ->where('model_type', User::class)
            ->where('role_id', $role->id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $assignment) {
            return response()->json([
                'error' => 'Role not assigned to user',
            ], Response::HTTP_NOT_FOUND);
        }

        $newValidUntil = Carbon::parse($request->string('valid_until')->toString());

        // Determine reason: use provided reason, or keep original, or default to 'Expiration extended'
        $reason = $request->string('reason', '')->toString() ?: $assignment->reason ?: 'Expiration extended';

        DB::transaction(function () use ($assignment, $newValidUntil, $user, $role, $reason, $authUser) {
            // Update expiration using a direct DB query because the pivot table uses a composite key (model_id, role_id, tenant_id) and Eloquent does not support updates without a single primary key.
            DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->where('model_type', User::class)
                ->where('role_id', $role->id)
                ->where('tenant_id', $assignment->tenant_id)
                ->update([
                    'valid_until' => $newValidUntil,
                    'reason' => $reason,
                    'updated_at' => now(),
                ]);

            // Log extension
            RoleAssignmentLog::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'action' => 'extended',
                'valid_from' => $assignment->valid_from,
                'valid_until' => $newValidUntil,
                'assigned_by' => $authUser->id,
                'reason' => $reason,
            ]);
        });

        return response()->json([
            'user_id' => $user->id,
            'role' => $role->name,
            'valid_from' => $assignment->valid_from?->toIso8601String(),
            'valid_until' => $newValidUntil->toIso8601String(),
            'reason' => $reason,
        ]);
    }
}
