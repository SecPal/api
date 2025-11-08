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
     * POST /v1/users/{id}/roles
     */
    public function store(AssignRoleRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $role = Role::where('name', (string) $request->input('role'))->firstOrFail();

        $validFrom = $request->input('valid_from') ? \Carbon\Carbon::parse((string) $request->input('valid_from')) : now();
        $validUntil = $request->input('valid_until') ? \Carbon\Carbon::parse((string) $request->input('valid_until')) : null;

        $tenantId = $request->user()->currentTeam->id ?? 1;

        DB::transaction(function () use ($user, $role, $validFrom, $validUntil, $request, $tenantId) {
            // Assign role using helper function
            assignTemporalRole($user, $role, $tenantId, [
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'auto_revoke' => $request->boolean('auto_revoke', true),
                'assigned_by' => auth()->id(), // @phpstan-ignore method.nonObject
                'reason' => $request->input('reason'),
            ]);

            // Log assignment
            RoleAssignmentLog::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'action' => 'assigned',
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'assigned_by' => auth()->id(), // @phpstan-ignore method.nonObject
                'reason' => $request->input('reason'),
            ]);
        });

        return response()->json([
            'user_id' => $user->id,
            'role' => $role->name,
            'valid_from' => $validFrom->toIso8601String(),
            'valid_until' => $validUntil?->toIso8601String(),
            'auto_revoke' => $request->boolean('auto_revoke', true),
            'reason' => $request->input('reason'),
        ], Response::HTTP_CREATED);
    }

    /**
     * List all roles for a user with their expiration status.
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->currentTeam->id ?? 1;

        $roleAssignments = TemporalRoleUser::where('model_id', $id)
            ->where('model_type', User::class)
            ->where('tenant_id', $tenantId)
            ->get();

        $roles = $roleAssignments->map(function ($assignment) {
            $now = now();
            $isActive = (! $assignment->valid_from || $assignment->valid_from->lte($now))
                && (! $assignment->valid_until || $assignment->valid_until->gte($now));

            // Get role name from role_id
            $role = Role::find($assignment->role_id);

            return [
                'role' => $role?->name ?? 'unknown',
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
     * DELETE /v1/users/{id}/roles/{role}
     */
    public function destroy(Request $request, int $id, string $roleName): JsonResponse
    {
        $user = User::findOrFail($id);
        $role = Role::where('name', $roleName)->firstOrFail();
        $tenantId = $request->user()->currentTeam->id ?? 1;

        // Check if role is assigned
        $assignment = TemporalRoleUser::where('model_id', $user->id)
            ->where('model_type', 'App\\Models\\User')
            ->where('role_id', $role->id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $assignment) {
            return response()->json([
                'error' => 'Role not assigned to user',
            ], Response::HTTP_NOT_FOUND);
        }

        DB::transaction(function () use ($user, $role, $assignment) {
            // Log revocation
            RoleAssignmentLog::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'action' => 'revoked',
                'valid_from' => $assignment->valid_from,
                'valid_until' => $assignment->valid_until,
                'assigned_by' => auth()->id(), // @phpstan-ignore method.nonObject
                'reason' => 'Manual revocation',
            ]);

            // Remove role
            $user->removeRole($role->name);
        });

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Extend the expiration date of a role assignment.
     *
     * PATCH /v1/users/{id}/roles/{role}/extend
     */
    public function extend(ExtendRoleRequest $request, int $id, string $roleName): JsonResponse
    {
        $user = User::findOrFail($id);
        $role = Role::where('name', $roleName)->firstOrFail();
        $tenantId = $request->user()->currentTeam->id ?? 1;

        // Find assignment
        $assignment = TemporalRoleUser::where('model_id', $user->id)
            ->where('model_type', 'App\\Models\\User')
            ->where('role_id', $role->id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $assignment) {
            return response()->json([
                'error' => 'Role not assigned to user',
            ], Response::HTTP_NOT_FOUND);
        }

        $newValidUntil = \Carbon\Carbon::parse((string) $request->input('valid_until'));

        DB::transaction(function () use ($assignment, $newValidUntil, $user, $role, $request) {
            // Update expiration
            $assignment->valid_until = $newValidUntil;
            $assignment->reason = $request->input('reason') ?? $assignment->reason;
            $assignment->save();

            // Log extension
            RoleAssignmentLog::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'action' => 'extended',
                'valid_from' => $assignment->valid_from,
                'valid_until' => $newValidUntil,
                'assigned_by' => auth()->id(), // @phpstan-ignore method.nonObject
                'reason' => $request->input('reason', 'Expiration extended'),
            ]);
        });

        return response()->json([
            'user_id' => $user->id,
            'role' => $role->name,
            'valid_from' => $assignment->valid_from?->toIso8601String(),
            'valid_until' => $newValidUntil->toIso8601String(),
            'reason' => $request->input('reason'),
        ]);
    }
}
