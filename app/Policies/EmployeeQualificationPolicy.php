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
     * Users with employee_qualification.read permission can view qualifications.
     * Scope-based filtering handled at controller level.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('employee_qualification.read');
    }

    /**
     * Determine if user can view a specific employee qualification.
     *
     * Employee can view own qualifications.
     * Users with employee_qualification.read permission can view with scope checks.
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

        // Users with permission can view
        if ($user->can('employee_qualification.read')) {
            // Validate organizational scope if applicable
            if ($employee->organizationalUnit !== null) {
                return $user->hasAccessToUnit($employee->organizationalUnit);
            }
            // Admin/HR: Can view all
            return true;
        }

        return false;
    }

    /**
     * Determine if user can create employee qualifications.
     *
     * Users with employee_qualification.write permission can assign qualifications.
     * Scope validation enforced at controller level.
     */
    public function create(User $user): bool
    {
        return $user->can('employee_qualification.write');
    }

    /**
     * Determine if user can update an employee qualification.
     *
     * Users with employee_qualification.write permission can update with scope validation.
     */
    public function update(User $user, EmployeeQualification $employeeQualification): bool
    {
        if (!$user->can('employee_qualification.write')) {
            return false;
        }

        $employee = $employeeQualification->employee;
        if ($employee === null) {
            return false;
        }

        // Validate organizational scope if applicable
        if ($employee->organizationalUnit !== null) {
            return $user->hasAccessToUnit($employee->organizationalUnit);
        }

        // Admin/HR: Can update all
        return true;
    }

    /**
     * Determine if user can delete an employee qualification.
     *
     * Users with employee_qualification.write permission can delete with scope validation.
     */
    public function delete(User $user, EmployeeQualification $employeeQualification): bool
    {
        if (!$user->can('employee_qualification.write')) {
            return false;
        }

        $employee = $employeeQualification->employee;
        if ($employee === null) {
            return false;
        }

        // Validate organizational scope if applicable
        if ($employee->organizationalUnit !== null) {
            return $user->hasAccessToUnit($employee->organizationalUnit);
        }

        // Admin/HR: Can delete all
        return true;
    }
}
