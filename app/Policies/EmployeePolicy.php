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
     * Employee can view own profile.
     * Users with employee.read permission can view employees.
     * Scope-based access: Users with organizational scopes are restricted to their scope.
     */
    public function view(User $user, Employee $employee): bool
    {
        // Employee can view own profile
        if ($user->id === $employee->user_id) {
            return true;
        }

        // Users with employee.read permission can view
        if (! $user->can('employee.read')) {
            return false;
        }

        // Check if user has organizational scopes (Manager role)
        $hasScopes = $user->organizationalScopes()->exists();

        if ($hasScopes && $employee->organizationalUnit !== null) {
            // Managers: Check organizational scope
            return $user->hasAccessToUnit($employee->organizationalUnit);
        }

        // Admin/HR (no scopes): Can view all
        return ! $hasScopes;
    }

    /**
     * Determine if user can create employees.
     *
     * Users with employee.write permission can create employees.
     */
    public function create(User $user): bool
    {
        return $user->can('employee.write');
    }

    /**
     * Determine if user can update an employee.
     *
     * Employee can update own profile (limited fields).
     * Users with employee.write permission can update all employees.
     */
    public function update(User $user, Employee $employee): bool
    {
        // Employee can update own profile (limited fields)
        // Note: Field-level restrictions handled in controller/request validation
        if ($user->id === $employee->user_id) {
            return true;
        }

        // Users with employee.write permission can update
        return $user->can('employee.write');
    }

    /**
     * Determine if user can delete an employee.
     *
     * Users with employee.write permission can delete employees (soft delete).
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->can('employee.write');
    }

    /**
     * Determine if user can activate an employee.
     *
     * Users with employee.write permission can activate employees (transition to active status).
     */
    public function activate(User $user, Employee $employee): bool
    {
        return $user->can('employee.write');
    }

    /**
     * Determine if user can terminate an employee.
     *
     * Users with employee.write permission can terminate employees (transition to terminated status).
     */
    public function terminate(User $user, Employee $employee): bool
    {
        return $user->can('employee.write');
    }
}
