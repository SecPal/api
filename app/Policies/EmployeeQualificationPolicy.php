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
     * Admin can assign qualifications to any employee.
     * Managers can only assign qualifications to employees in their scope.
     *
     * Note: Scope validation for managers must be enforced at the controller level
     * by checking $user->hasAccessToUnit($employee->organizationalUnit) before creation.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Admin') || $user->hasRole('Manager');
    }

    /**
     * Determine if user can update an employee qualification.
     *
     * Admin can update any qualification.
     * Managers can only update qualifications for employees in their scope.
     */
    public function update(User $user, EmployeeQualification $employeeQualification): bool
    {
        // Admin can update all
        if ($user->hasRole('Admin')) {
            return true;
        }

        $employee = $employeeQualification->employee;
        if ($employee === null) {
            return false;
        }

        // Manager can only update qualifications for employees in their scope
        if ($user->hasRole('Manager') && $employee->organizationalUnit !== null) {
            return $user->hasAccessToUnit($employee->organizationalUnit);
        }

        return false;
    }

    /**
     * Determine if user can delete an employee qualification.
     *
     * Admin can delete any qualification.
     * Managers can only delete qualifications for employees in their scope.
     */
    public function delete(User $user, EmployeeQualification $employeeQualification): bool
    {
        // Admin can delete all
        if ($user->hasRole('Admin')) {
            return true;
        }

        $employee = $employeeQualification->employee;
        if ($employee === null) {
            return false;
        }

        // Manager can only delete qualifications for employees in their scope
        if ($user->hasRole('Manager') && $employee->organizationalUnit !== null) {
            return $user->hasAccessToUnit($employee->organizationalUnit);
        }

        return false;
    }
}
