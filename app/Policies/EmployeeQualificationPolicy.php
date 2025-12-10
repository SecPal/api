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
        // Always allow - specific filtering happens in view()
        return true;
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
        // Employee can view own qualifications
        if ($user->id === $employeeQualification->employee->user_id) {
            return true;
        }

        // HR can view all
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Manager can view qualifications for employees in their scope
        if ($user->hasRole('Manager') && $employeeQualification->employee->organizationalUnit !== null) {
            return $user->hasAccessToUnit($employeeQualification->employee->organizationalUnit);
        }

        return false;
    }

    /**
     * Determine if user can create employee qualifications.
     *
     * Only HR can assign qualifications to employees.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can update an employee qualification.
     *
     * Only HR can update certificate details.
     */
    public function update(User $user, EmployeeQualification $employeeQualification): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can delete an employee qualification.
     *
     * Only HR can detach qualifications from employees.
     */
    public function delete(User $user, EmployeeQualification $employeeQualification): bool
    {
        return $user->hasRole('Admin');
    }
}
