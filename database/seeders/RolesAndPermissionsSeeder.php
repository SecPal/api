<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seed roles and permissions for the RBAC system.
     *
     * This seeder is idempotent - it can be run multiple times safely.
     * - Uses firstOrCreate to avoid duplicates
     * - Only syncs permissions if role has none
     * - Recreates deleted predefined roles
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Define all permissions grouped by resource
        $permissions = $this->getPermissionDefinitions();

        // Create permissions (idempotent)
        foreach ($permissions as $resource => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(
                    ['name' => "{$resource}.{$action}"],
                    ['guard_name' => 'sanctum']
                );
            }
        }

        // Define predefined roles
        $roles = $this->getRoleDefinitions();

        // Create roles and assign permissions (idempotent)
        foreach ($roles as $roleName => $roleConfig) {
            $role = Role::firstOrCreate(
                ['name' => $roleName],
                ['guard_name' => 'sanctum']
            );

            // Only sync permissions if role has none
            // This prevents overwriting customized permissions
            if ($role->permissions()->count() === 0) {
                $permissionNames = $this->expandWildcardPermissions($roleConfig['permissions'], $permissions);
                $role->syncPermissions($permissionNames);
            }
        }
    }

    /**
     * Get all permission definitions grouped by resource.
     *
     * @return array<string, array<int, string>>
     */
    private function getPermissionDefinitions(): array
    {
        return [
            'employees' => [
                'read',
                'create',
                'update',
                'delete',
                'read_salary',
                'read_all_branches',
                'export',
            ],
            'shifts' => [
                'read',
                'create',
                'update',
                'delete',
                'publish',
                'approve_as_br',
            ],
            'work_instructions' => [
                'read',
                'create',
                'update',
                'delete',
                'publish',
                'acknowledge',
                'view_acknowledgments',
            ],
            'roles' => [
                'read',
                'create',
                'update',
                'delete',
                'assign_temporary',
                'extend_expiration',
            ],
            'permissions' => [
                'read',
                'create',
                'update',
                'delete',
                'assign_direct',
                'revoke_direct',
            ],
            'works_council' => [
                'access_employee_files',
                'approve_shift_plans',
            ],
            'reports' => [
                'generate',
                'view',
                'export',
            ],
        ];
    }

    /**
     * Get predefined role definitions with their permissions.
     *
     * @return array<string, array{permissions: array<int, string>}>
     */
    private function getRoleDefinitions(): array
    {
        return [
            'Admin' => [
                'permissions' => [
                    'employees.*',
                    'shifts.*',
                    'work_instructions.*',
                    'roles.*',
                    'permissions.*',
                    'works_council.*',
                    'reports.*',
                ],
            ],
            'Manager' => [
                'permissions' => [
                    'employees.read',
                    'employees.create',
                    'employees.update',
                    'employees.read_salary',
                    'shifts.read',
                    'shifts.create',
                    'shifts.update',
                    'shifts.delete',
                    'shifts.publish',
                    'work_instructions.read',
                    'work_instructions.create',
                    'work_instructions.update',
                    'work_instructions.publish',
                    'work_instructions.view_acknowledgments',
                    'reports.view',
                    'reports.generate',
                ],
            ],
            'Guard' => [
                'permissions' => [
                    'employees.read', // Own data only (enforced by policy)
                    'shifts.read',
                    'shifts.update', // Own shifts only (enforced by policy)
                    'work_instructions.read',
                    'work_instructions.acknowledge',
                ],
            ],
            'Client' => [
                'permissions' => [
                    'shifts.read', // Location-specific (enforced by policy)
                    'work_instructions.read',
                    'reports.view',
                ],
            ],
            'Works Council' => [
                'permissions' => [
                    'employees.read',
                    'employees.read_all_branches',
                    'shifts.read',
                    'shifts.approve_as_br',
                    'work_instructions.read',
                    'works_council.access_employee_files',
                    'works_council.approve_shift_plans',
                    'reports.view',
                ],
            ],
        ];
    }

    /**
     * Expand wildcard permissions (e.g., "employees.*") to all resource actions.
     *
     * @param  array<int, string>  $permissions  Permission names (may include wildcards)
     * @param  array<string, array<int, string>>  $allPermissions  Full permission definitions
     * @return array<int, string> Expanded permission names
     */
    private function expandWildcardPermissions(array $permissions, array $allPermissions): array
    {
        $expanded = [];

        foreach ($permissions as $permission) {
            // Check if wildcard permission (e.g., "employees.*")
            if (str_ends_with($permission, '.*')) {
                $resource = substr($permission, 0, -2);

                // Add all actions for this resource
                if (isset($allPermissions[$resource])) {
                    foreach ($allPermissions[$resource] as $action) {
                        $expanded[] = "{$resource}.{$action}";
                    }
                }
            } else {
                // Regular permission
                $expanded[] = $permission;
            }
        }

        return array_unique($expanded);
    }
}
