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
            // Phase 5: Employee Management API
            'employee' => [
                'read',
                'write', // Convenience permission for create+update+delete
                'create',
                'update',
                'delete',
                'activate',
                'terminate',
            ],
            'employee_qualification' => [
                'read',
                'write',
                'create',
                'update',
                'delete',
            ],
            'employee_document' => [
                'read',
                'write',
                'create',
                'update',
                'delete',
            ],
            'qualification' => [
                'read',
                'write',
            ],
            'onboarding' => [
                'read',
                'write',
                'create',
                'update',
                'delete',
                'approve',
            ],
            'onboarding_template' => [
                'read',
                'write',
                'create',
                'update',
                'delete',
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
            'role' => [
                'assign',   // Phase 3: POST /users/{user}/roles
                'read',     // Phase 3: GET /users/{user}/roles
                'revoke',   // Phase 3: DELETE /users/{user}/roles/{role}
            ],
            'roles' => [
                'read',               // Phase 4: GET /roles
                'create',             // Phase 4: POST /roles
                'update',             // Phase 4: PATCH /roles/{id}
                'delete',             // Phase 4: DELETE /roles/{id}
                'assign_temporary',   // Phase 3: Temporal role assignment
                'extend_expiration',  // Phase 3: PATCH /users/{user}/roles/{role}/extend
            ],
            'permissions' => [
                'read',           // Phase 4: GET /permissions
                'create',         // Phase 4: POST /permissions
                'update',         // Phase 4: PATCH /permissions/{id}
                'delete',         // Phase 4: DELETE /permissions/{id}
                'assign_direct',  // Phase 4: POST /users/{user}/permissions
                'revoke_direct',  // Phase 4: DELETE /users/{user}/permissions/{permission}
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
                    'role.*',        // Phase 3: Role assignment permissions
                    'roles.*',       // Phase 4: Role management permissions
                    'permissions.*', // Phase 4: Permission management permissions
                    'works_council.*',
                    // Phase 5: Employee Management API
                    'employee.*',
                    'employee_qualification.*',
                    'employee_document.*',
                    'qualification.*',
                    'onboarding.*',
                    'onboarding_template.*',
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
                    // Phase 5: Employee Management API
                    'employee.read',
                    'employee.update', // Can update employee data (but not create/delete/activate/terminate)
                    'employee_qualification.read',
                    'employee_qualification.write',
                    'employee_document.read',
                    'employee_document.write',
                    'qualification.read',
                    'onboarding.read',
                    'onboarding.write',
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
