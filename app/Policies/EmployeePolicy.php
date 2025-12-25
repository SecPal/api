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
     * Employee can view own profile (if allow_self_access = true in scope).
     * Users with employee.read permission can view employees.
     * Scope-based access: Users with organizational scopes are restricted to their scope.
     * Leadership Level filtering: Users can only view employees within their viewable rank range.
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

            // Performance: Eager load leadershipLevel to avoid N+1 queries when checking multiple employees
            $employee->loadMissing('leadershipLevel');
            $employeeRank = $employee->leadershipLevel?->rank;

            foreach ($scopes as $scope) {
                // Check rank filtering
                if ($this->isWithinViewableRankRange($employeeRank, $scope->min_viewable_rank, $scope->max_viewable_rank)) {
                    return true; // Employee visible in at least one scope
                }
            }

            return false; // Employee not visible in any scope
        }

        // Admin/HR (no scopes at all): Can view all
        return $allScopes->isEmpty();
    }

    /**
     * Check if employee's rank is within user's viewable rank range.
     *
     * CRITICAL SEMANTICS:
     * - max_viewable_rank = NULL or 0 → ONLY employees with rank = NULL (non-leadership)
     * - max_viewable_rank = 255 → All leadership levels (FE1-FE255)
     *
     * @param  int|null  $employeeRank  Employee's leadership rank (NULL for non-leadership)
     * @param  int|null  $minViewableRank  Minimum viewable rank (NULL = no minimum)
     * @param  int|null  $maxViewableRank  Maximum viewable rank (NULL/0 = ONLY non-leadership)
     */
    private function isWithinViewableRankRange(?int $employeeRank, ?int $minViewableRank, ?int $maxViewableRank): bool
    {
        // Case 1: max_viewable_rank = NULL or 0 → ONLY non-leadership employees
        if ($maxViewableRank === null || $maxViewableRank === 0) {
            return $employeeRank === null; // Only non-leadership visible
        }

        // Case 2: Employee has NO leadership level (Guard)
        if ($employeeRank === null) {
            return false; // Non-leadership NOT visible in leadership-only scope
        }

        // Case 3: Check if employee's rank is within range
        if (! is_null($minViewableRank) && $employeeRank < $minViewableRank) {
            return false; // Below minimum
        }

        if (! is_null($maxViewableRank) && $employeeRank > $maxViewableRank) { // @phpstan-ignore function.impossibleType
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

            // Performance: Eager load leadershipLevel to avoid N+1 queries when checking multiple employees
            $employee->loadMissing('leadershipLevel');
            $employeeRank = $employee->leadershipLevel?->rank;

            foreach ($scopes as $scope) {
                // Check rank filtering
                if ($this->isWithinViewableRankRange($employeeRank, $scope->min_viewable_rank, $scope->max_viewable_rank)) {
                    return true; // Employee editable in at least one scope
                }
            }

            return false; // Employee not editable in any scope
        }

        // Admin/HR (no scopes at all): Can update all
        return $allScopes->isEmpty();
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
