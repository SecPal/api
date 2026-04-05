<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Employee Policy
 *
 * Authorization rules for employee management with scope-based access control.
 *
 * Rules:
 * - viewAny: HR role OR Manager (within scope)
 * - view: Own profile OR HR OR Manager (scope-based)
 * - create: HR only
 * - update: Own profile (limited fields) OR HR
 * - delete: HR only (soft delete)
 * - activate: HR only (change status to active)
 * - placeOnLeave: HR only (change status to on_leave)
 * - returnFromLeave: HR only (restore status to active)
 * - terminate: HR only (change status to terminated)
 */
class EmployeePolicy
{
    /**
     * Determine if user can view any employees.
     *
     * Users with employee.read permission can view employees.
     * Scope-based filtering handled at controller level for managers.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('employee.read');
    }

    /**
     * Determine if user can view a specific employee.
     *
     * AUTHORIZATION LOGIC (ADR-009):
     * 1. Tenant isolation check (always first)
     * 2. Self-access: User can view own profile IF allow_self_access=true in scope
     * 3. Permission check: Requires employee.read permission
     * 4. Organizational scope check: ALL users MUST have scopes to access organizational data
     * 5. Rank filtering: Employee must be within user's viewable rank range
     *
     * IMPORTANT: There is NO "Admin without scopes" - all users accessing
     * organizational features must have defined scopes (with appropriate rank ranges).
     */
    public function view(User $user, Employee $employee): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $employee->tenant_id) {
            return false;
        }

        // Self-access control (NEW - ADR-009)
        // Check if viewing own employee record
        if ($user->id === $employee->user_id) {
            // Requires permission AND allow_self_access = true in scope
            if (! $user->can('employee.read')) {
                return false;
            }

            // Check if user has scope with allow_self_access = true for this unit
            $scope = $user->organizationalScopes()
                ->where('organizational_unit_id', $employee->organizational_unit_id)
                ->where('allow_self_access', true)
                ->first();

            // If no scope with allow_self_access, deny access to own data
            return $scope !== null;
        }

        // Users with employee.read permission can view
        if (! $user->can('employee.read')) {
            return false;
        }

        // Check if user has organizational scopes (Manager role)
        // Optimization: Fetch all user's scopes once, filter in-memory (avoids 2 queries: exists + get)
        $allScopes = $user->organizationalScopes()->get();

        if ($allScopes->isNotEmpty() && $employee->organizationalUnit !== null) {
            // Managers: Check organizational scope AND leadership rank filtering
            // Filter scopes for THIS specific organizational unit
            $scopes = $allScopes->where('organizational_unit_id', $employee->organizational_unit_id);

            if ($scopes->isEmpty()) {
                return false; // Manager has scopes, but not for this unit
            }

            // Get employee's management level (simple integer field)
            $employeeRank = $employee->management_level;

            foreach ($scopes as $scope) {
                // Check rank filtering
                if ($this->isWithinViewableRankRange($employeeRank, $scope->min_viewable_rank, $scope->max_viewable_rank)) {
                    return true; // Employee visible in at least one scope
                }
            }

            return false; // Employee not visible in any scope
        }

        // No scopes = no access to organizational data
        // All users accessing employees MUST have organizational scopes
        return false;
    }

    /**
     * Check if employee's management level is within user's viewable range.
     *
     * CRITICAL SEMANTICS (ADR-009):
     * - Non-management employees have management_level = 0
     * - Management levels: 1-255 (1=CEO/highest, 255=lowest)
     *
     * Two separate scope systems (cannot be mixed):
     * - 0/0: ONLY non-management employees (Guards)
     * - 1-255: Management levels (e.g., 1/5 = ML1-ML5, 1/255 = all management)
     * - Invalid: 0/5 (cannot mix non-management with management levels)
     *
     * @param  int  $employeeLevel  Employee's management level (0=non-management, 1-255=management)
     * @param  int|null  $minViewableLevel  Minimum viewable level (0=non-management only, 1-255=management)
     * @param  int|null  $maxViewableLevel  Maximum viewable level (0=non-management only, 1-255=management)
     */
    private function isWithinViewableRankRange(int $employeeLevel, ?int $minViewableLevel, ?int $maxViewableLevel): bool
    {
        // Case 1: Scope 0/0 = ONLY non-management employees
        if ($maxViewableLevel === 0) {
            return $employeeLevel === 0; // Only non-management visible
        }

        // Case 2: Employee is non-management (level = 0)
        if ($employeeLevel === 0) {
            return false; // Non-management not visible in management scopes (1-255)
        }

        // Case 3: Management level scopes (1-255)
        // Check if management level within specified range
        if ($minViewableLevel !== null && $employeeLevel < $minViewableLevel) {
            return false; // Below minimum
        }

        if ($maxViewableLevel !== null && $employeeLevel > $maxViewableLevel) {
            return false; // Above maximum
        }

        return true; // Within range
    }

    /**
     * Determine if user can create employees.
     *
     * Users with employee.write or employee.create permission can create employees.
     */
    public function create(User $user): bool
    {
        return $user->can('employee.write') || $user->can('employee.create');
    }

    /**
     * Determine if user can update an employee.
     *
     * Employee can update own profile (limited fields) if allow_self_access = true.
     * Users with employee.update permission can update all employees.
     * Leadership Level filtering: Users with scopes can only edit employees within their viewable rank range.
     */
    public function update(User $user, Employee $employee): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $employee->tenant_id) {
            return false;
        }

        // Self-access control (NEW - ADR-009)
        // Employee can update own profile (limited fields)
        // Note: Field-level restrictions handled in controller/request validation
        if ($user->id === $employee->user_id) {
            // Requires permission AND allow_self_access = true in scope
            if (! $user->can('employee.update')) {
                return false;
            }

            // Check if user has scope with allow_self_access = true for this unit
            $scope = $user->organizationalScopes()
                ->where('organizational_unit_id', $employee->organizational_unit_id)
                ->where('allow_self_access', true)
                ->first();

            // If no scope with allow_self_access, deny access to own data
            return $scope !== null;
        }

        // Users with employee.write or employee.update permission can update
        if (! $user->can('employee.write') && ! $user->can('employee.update')) {
            return false;
        }

        // Check if user has organizational scopes (Manager role)
        // Optimization: Fetch all user's scopes once, filter in-memory (avoids 2 queries: exists + get)
        $allScopes = $user->organizationalScopes()->get();

        if ($allScopes->isNotEmpty() && $employee->organizationalUnit !== null) {
            // Managers: Check organizational scope AND leadership rank filtering
            // Filter scopes for THIS specific organizational unit
            $scopes = $allScopes->where('organizational_unit_id', $employee->organizational_unit_id);

            if ($scopes->isEmpty()) {
                return false; // Manager has scopes, but not for this unit
            }

            // Get employee's management level (simple integer field)
            $employeeRank = $employee->management_level;

            foreach ($scopes as $scope) {
                // Check rank filtering
                if ($this->isWithinViewableRankRange($employeeRank, $scope->min_viewable_rank, $scope->max_viewable_rank)) {
                    return true; // Employee editable in at least one scope
                }
            }

            return false; // Employee not editable in any scope
        }

        // No scopes = no access to organizational data
        // All users accessing employees MUST have organizational scopes
        return false;
    }

    /**
     * Determine if user can delete an employee.
     *
     * Users with employee.write or employee.delete permission can delete employees (soft delete).
     */
    public function delete(User $user, Employee $employee): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $employee->tenant_id) {
            return false;
        }

        return $user->can('employee.write') || $user->can('employee.delete');
    }

    /**
     * Determine if user can activate an employee.
     *
     * Users with employee.write or employee.activate permission can activate employees (transition to active status).
     */
    public function activate(User $user, Employee $employee): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $employee->tenant_id) {
            return false;
        }

        return $user->can('employee.write') || $user->can('employee.activate');
    }

    /**
     * Determine if user can confirm a pre-contract onboarding dossier.
     */
    public function confirmOnboarding(User $user, Employee $employee): bool
    {
        if ($user->tenant_id !== $employee->tenant_id) {
            return false;
        }

        return $user->can('onboarding.confirm');
    }

    /**
     * Determine if user can place an employee on leave.
     */
    public function placeOnLeave(User $user, Employee $employee): bool
    {
        if ($user->tenant_id !== $employee->tenant_id) {
            return false;
        }

        return $user->can('employee.write');
    }

    /**
     * Determine if user can restore an employee from leave.
     */
    public function returnFromLeave(User $user, Employee $employee): bool
    {
        if ($user->tenant_id !== $employee->tenant_id) {
            return false;
        }

        return $user->can('employee.write');
    }

    /**
     * Determine if user can terminate an employee.
     *
     * Users with employee.write or employee.terminate permission can terminate employees (transition to terminated status).
     */
    public function terminate(User $user, Employee $employee): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $employee->tenant_id) {
            return false;
        }

        return $user->can('employee.write') || $user->can('employee.terminate');
    }
}
