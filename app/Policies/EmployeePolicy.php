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
     * HR can view all employees.
     * Managers can view employees in their organizational scope.
     */
    public function viewAny(User $user): bool
    {
        // HR can view all
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Managers can view employees in their scope
        if ($user->hasRole('Manager')) {
            return true;
        }

        return false;
    }

    /**
     * Determine if user can view a specific employee.
     *
     * Employee can view own profile.
     * HR can view all employees.
     * Managers can view employees in their organizational scope.
     */
    public function view(User $user, Employee $employee): bool
    {
        // Employee can view own profile
        if ($user->id === $employee->user_id) {
            return true;
        }

        // HR can view all
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Manager can view employees in their organizational scope
        if ($user->hasRole('Manager') && $employee->organizationalUnit !== null) {
            return $user->hasAccessToUnit($employee->organizationalUnit);
        }

        return false;
    }

    /**
     * Determine if user can create employees.
     *
     * Only HR can create employees.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can update an employee.
     *
     * Employee can update own profile (limited fields).
     * HR can update all employees.
     */
    public function update(User $user, Employee $employee): bool
    {
        // Employee can update own profile (limited fields)
        // Note: Field-level restrictions handled in controller/request validation
        if ($user->id === $employee->user_id) {
            return true;
        }

        // HR can update all
        if ($user->hasRole('Admin')) {
            return true;
        }

        return false;
    }

    /**
     * Determine if user can delete an employee.
     *
     * Only HR can delete employees (soft delete).
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can activate an employee.
     *
     * Only HR can activate employees (transition to active status).
     */
    public function activate(User $user, Employee $employee): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can terminate an employee.
     *
     * Only HR can terminate employees (transition to terminated status).
     */
    public function terminate(User $user, Employee $employee): bool
    {
        return $user->hasRole('Admin');
    }
}
