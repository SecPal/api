<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateRoleRequest;
use App\Http\Requests\Api\V1\UpdateRoleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

class RoleManagementController extends Controller
{
    /**
     * List all roles with permission count and user count.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions_count' => $role->permissions_count,
                'users_count' => $role->users_count,
                'created_at' => $role->created_at?->toIso8601String() ?? '',
                'updated_at' => $role->updated_at?->toIso8601String() ?? '',
            ]),
        ]);
    }

    /**
     * Create a new role with permissions.
     */
    public function store(CreateRoleRequest $request): JsonResponse
    {
        Gate::authorize('create', Role::class);

        /** @var string $name */
        $name = $request->input('name');
        $role = Role::create(['name' => $name]);

        /** @var array<int, string> $permissions */
        $permissions = $request->input('permissions', []);
        if (! empty($permissions)) {
            $role->syncPermissions($permissions);
        }

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->fresh()?->permissions->pluck('name') ?? [],
                'created_at' => $role->created_at?->toIso8601String() ?? '',
                'updated_at' => $role->updated_at?->toIso8601String() ?? '',
            ],
        ], 201);
    }

    /**
     * Get role details with assigned permissions.
     */
    public function show(int $id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);
        Gate::authorize('view', $role);

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $role->users()->count(),
                'created_at' => $role->created_at?->toIso8601String() ?? '',
                'updated_at' => $role->updated_at?->toIso8601String() ?? '',
            ],
        ]);
    }

    /**
     * Update role name and/or permissions.
     */
    public function update(UpdateRoleRequest $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);
        Gate::authorize('update', $role);

        if ($request->filled('name')) {
            /** @var string $name */
            $name = $request->input('name');
            $role->name = $name;
            $role->save();
        }

        if ($request->has('permissions')) {
            /** @var array<int, string> $permissions */
            $permissions = $request->input('permissions', []);
            $role->syncPermissions($permissions);
        }

        $freshRole = $role->fresh();

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $freshRole?->permissions->pluck('name') ?? [],
                'created_at' => $role->created_at?->toIso8601String() ?? '',
                'updated_at' => $role->updated_at?->toIso8601String() ?? '',
            ],
        ]);
    }

    /**
     * Delete role (only if not assigned to any users).
     */
    public function destroy(int $id): Response|JsonResponse
    {
        $role = Role::findOrFail($id);
        Gate::authorize('delete', $role);

        $usersCount = $role->users()->count();

        if ($usersCount > 0) {
            return response()->json([
                'message' => __('Cannot delete role while assigned to users'),
                'assigned_to' => $usersCount,
            ], 422);
        }

        $role->delete();

        return response()->noContent();
    }
}
