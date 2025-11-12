<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePermissionRequest;
use App\Http\Requests\Api\V1\UpdatePermissionRequest;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PermissionManagementController extends Controller
{
    /**
     * List all permissions grouped by resource.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Permission::class);

        $permissions = Permission::with('roles')
            ->orderBy('name')
            ->get();

        // Group permissions by resource (first part before dot)
        $grouped = $permissions->groupBy(function (Permission $permission) {
            $parts = explode('.', $permission->name);

            return $parts[0] ?? 'other';
        });

        /** @var array<string, array<int, array{id: int, name: string, description: string|null, roles_count: int, created_at: string, updated_at: string}>> $data */
        $data = $grouped->map(function ($resourcePermissions) {
            return $resourcePermissions->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'description' => $permission->description,
                'roles_count' => $permission->roles->count(),
                'created_at' => $permission->created_at->toIso8601String(),
                'updated_at' => $permission->updated_at->toIso8601String(),
            ])->values();
        })->toArray();

        return response()->json(['data' => $data]);
    }

    /**
     * Create a new permission.
     */
    public function store(CreatePermissionRequest $request): JsonResponse
    {
        Gate::authorize('create', Permission::class);

        /** @var string $name */
        $name = $request->input('name');

        /** @var string|null $description */
        $description = $request->input('description');

        /** @var Permission $permission */
        $permission = Permission::create([
            'name' => $name,
            'guard_name' => 'sanctum',
            'description' => $description,
        ]);

        return response()->json([
            'data' => [
                'id' => $permission->id,
                'name' => $permission->name,
                'description' => $permission->description,
                'created_at' => $permission->created_at->toIso8601String(),
                'updated_at' => $permission->updated_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Get permission details with assigned roles.
     */
    public function show(int $id): JsonResponse
    {
        /** @var Permission $permission */
        $permission = Permission::with('roles')->findOrFail($id);
        Gate::authorize('view', $permission);

        return response()->json([
            'data' => [
                'id' => $permission->id,
                'name' => $permission->name,
                'description' => $permission->description,
                'roles' => $permission->roles->pluck('name'),
                'created_at' => $permission->created_at->toIso8601String(),
                'updated_at' => $permission->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Update permission (description only, name is immutable).
     */
    public function update(UpdatePermissionRequest $request, int $id): JsonResponse
    {
        $permission = Permission::findOrFail($id);
        Gate::authorize('update', $permission);

        if ($request->filled('description')) {
            /** @var string $description */
            $description = $request->input('description');
            $permission->description = $description;
            $permission->save();
        }

        return response()->json([
            'data' => [
                'id' => $permission->id,
                'name' => $permission->name,
                'description' => $permission->description,
                'created_at' => $permission->created_at->toIso8601String(),
                'updated_at' => $permission->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Delete permission (only if not assigned to any roles).
     */
    public function destroy(int $id): Response|JsonResponse
    {
        $permission = Permission::findOrFail($id);
        Gate::authorize('delete', $permission);

        $rolesCount = $permission->roles()->count();

        if ($rolesCount > 0) {
            return response()->json([
                'message' => 'Cannot delete permission while assigned to roles',
                'assigned_to_roles' => $rolesCount,
            ], 422);
        }

        $permission->delete();

        return response()->noContent();
    }
}
