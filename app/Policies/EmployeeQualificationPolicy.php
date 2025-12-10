<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\EmployeeQualification;
use App\Models\User;

/**
 * Employee Qualification Policy
 *
 * Authorization rules for employee-qualification pivot relationships.
 *
 * Rules:
 * - viewAny: Employee (own qualifications) OR HR OR Manager (scope)
 * - view: Check employee access
 * - create: HR only
 * - update: HR only (certificate details)
 * - delete: HR only
 */
class EmployeeQualificationPolicy
{
    /**
     * Determine if user can view any employee qualifications.
     *
     * Employee can view own qualifications.
     * HR can view all.
     * Managers can view qualifications for employees in their scope.
     */
    public function viewAny(User $user): bool
    {
        // Admin and Manager can view any (with scope filtering in queries)
        return $user->hasRole('Admin') || $user->hasRole('Manager');
    }

    /**
     * Determine if user can view a specific employee qualification.
     *
     * Employee can view own qualifications.
     * HR can view all.
     * Managers can view qualifications for employees in their scope.
     */
    public function view(User $user, EmployeeQualification $employeeQualification): bool
    {
        $employee = $employeeQualification->employee;
        if ($employee === null) {
            return false;
        }

        // Employee can view own qualifications
        if ($user->id === $employee->user_id) {
            return true;
        }

        // Admin can view all
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Manager can view qualifications for employees in their scope
        if ($user->hasRole('Manager') && $employee->organizationalUnit !== null) {
            return $user->hasAccessToUnit($employee->organizationalUnit);
        }

        return false;
    }

    /**
     * Determine if user can create employee qualifications.
     *
     * Admin and Managers can assign qualifications to employees.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Admin') || $user->hasRole('Manager');
    }

    /**
     * Determine if user can update an employee qualification.
     *
     * Admin and Managers can update certificate details.
     */
    public function update(User $user, EmployeeQualification $employeeQualification): bool
    {
        return $user->hasRole('Admin') || $user->hasRole('Manager');
    }

    /**
     * Determine if user can delete an employee qualification.
     *
     * Admin and Managers can detach qualifications from employees.
     */
    public function delete(User $user, EmployeeQualification $employeeQualification): bool
    {
        return $user->hasRole('Admin') || $user->hasRole('Manager');
    }
}
