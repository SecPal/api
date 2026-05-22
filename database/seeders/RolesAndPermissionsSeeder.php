<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
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

            $permissionNames = $this->expandWildcardPermissions($roleConfig['permissions'], $permissions);

            if ($role->permissions()->count() === 0) {
                // New install: full sync for the role
                $role->syncPermissions($permissionNames);
            } else {
                // Existing install: grant any missing defined permissions without
                // removing custom ones that may have been added post-install.
                /** @var array<string> $existingPermissionNames */
                $existingPermissionNames = $role->permissions()->pluck('name')->toArray();
                $missing = array_diff($permissionNames, $existingPermissionNames);
                if (! empty($missing)) {
                    $role->givePermissionTo($missing);
                }
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
            // Epic #210: Customer & Site Management
            'customers' => [
                'read',
                'create',
                'update',
                'delete',
            ],
            'sites' => [
                'read',
                'create',
                'update',
                'delete',
            ],
            'assignments' => [
                'create',
                'update',
                'delete',
            ],
            'cost-centers' => [
                'read',
                'create',
                'update',
                'delete',
            ],
            // Epic #211: Employee Management
            'employees' => [
                'read',
                'create',
                'update',
                'delete',
                'read_sensitive',
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
            // Epic #399: Leadership Levels System (Issue #424)
            'leadership_level' => [
                'view',
                'create',
                'update',
                'delete',
            ],
            // Epic #385: Activity Logging & Audit Trail (Issue #396)
            'activity_log' => [
                'read',
                'read_all', // Access to global logs (no organizational unit)
                'read_system', // View activities from privileged or system actors (Issue #440)
            ],
            'onboarding' => [
                'read',
                'write',
                'create',
                'update',
                'delete',
                'approve',
                'confirm',
            ],
            'android_enrollment' => [
                'read',
                'write',
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
                'read',           // View another user's permissions (GET /users/{user}/permissions)
                'assign_direct',  // Phase 4: POST /users/{user}/permissions
                'revoke_direct',  // Phase 4: DELETE /users/{user}/permissions/{permission}
            ],
            'users' => [
                'reset_mfa',      // MFA safeguards: DELETE /users/{user}/mfa
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
            'Employee' => [
                'permissions' => [
                    'employees.read',
                    'employee.read',
                    'employee.update',
                    'employee_qualification.read',
                    'employee_document.read',
                    'qualification.read',
                    'shifts.read',
                    'shifts.update',
                    'work_instructions.read',
                    'work_instructions.acknowledge',
                ],
            ],
            'Employee Read Only' => [
                'permissions' => [
                    'employees.read',
                    'employee.read',
                    'employee_qualification.read',
                    'employee_document.read',
                    'qualification.read',
                    'shifts.read',
                    'work_instructions.read',
                ],
            ],
            'HR' => [
                'permissions' => [
                    'employees.read',
                    'employees.create',
                    'employees.update',
                    'employees.delete',
                    'employees.read_sensitive',
                    'employees.read_salary',
                    'employees.read_all_branches',
                    'employees.export',
                    'employee.read',
                    'employee.write',
                    'employee.create',
                    'employee.update',
                    'employee.delete',
                    'employee.activate',
                    'employee.terminate',
                    'employee_qualification.read',
                    'employee_qualification.write',
                    'employee_document.read',
                    'employee_document.write',
                    'qualification.read',
                    'qualification.write',
                    'onboarding.read',
                    'onboarding.write',
                    'onboarding.approve',
                    'onboarding.confirm',
                    'reports.view',
                    'reports.generate',
                ],
            ],
            'Manager' => [
                'permissions' => [
                    // Epic #210: Customer & Site Management
                    'customers.read',
                    'customers.create',
                    'customers.update',
                    'sites.read',
                    'sites.create',
                    'sites.update',
                    'assignments.create',
                    'assignments.update',
                    'cost-centers.read',
                    'cost-centers.create',
                    'cost-centers.update',
                    // Epic #211: Employee Management
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
                    // Epic #385: Activity Logging & Audit Trail (Issue #396)
                    'activity_log.read',
                    'activity_log.read_system', // View activities from privileged or system actors (Issue #440)
                    'onboarding.read',
                    'onboarding.write',
                    'android_enrollment.read',
                    'android_enrollment.write',
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
