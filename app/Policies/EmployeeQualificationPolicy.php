<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Policies;

use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\User;

/**
 * Employee Qualification Policy
 *
 * Authorization rules for employee-qualification pivot relationships.
 *
 * Rules:
 * - viewAny/view: Employee (own qualifications) OR same-tenant, unscoped read access
 * - create/update/delete: Same-tenant, unscoped write access
 */
class EmployeeQualificationPolicy
{
    /**
     * Determine if user can view any employee qualifications.
     *
     * Employees can view their own qualifications. Other users require the
     * dedicated read permission without an organizational scope.
     */
    public function viewAny(User $user, ?Employee $employee = null): bool
    {
        if ($employee !== null && $user->tenant_id !== $employee->tenant_id) {
            return false;
        }

        if ($employee !== null && $user->id === $employee->user_id) {
            return true;
        }

        return $user->can('employee_qualification.read')
            && ! $user->organizationalScopes()->exists();
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

        return $this->viewAny($user, $employee);
    }

    /**
     * Determine if user can create employee qualifications.
     *
     * Users require the dedicated write permission without an organizational scope.
     */
    public function create(User $user, Employee $employee): bool
    {
        return $this->hasUnrestrictedWriteAccess($user, $employee);
    }

    /**
     * Determine if user can update an employee qualification.
     *
     * Users require same-tenant, unscoped employee_qualification.write access.
     */
    public function update(User $user, EmployeeQualification $employeeQualification): bool
    {
        $employee = $employeeQualification->employee;

        return $employee !== null
            && $this->hasUnrestrictedWriteAccess($user, $employee);
    }

    /**
     * Determine if user can delete an employee qualification.
     *
     * Users require same-tenant, unscoped employee_qualification.write access.
     */
    public function delete(User $user, EmployeeQualification $employeeQualification): bool
    {
        $employee = $employeeQualification->employee;

        return $employee !== null
            && $this->hasUnrestrictedWriteAccess($user, $employee);
    }

    private function hasUnrestrictedWriteAccess(User $user, Employee $employee): bool
    {
        return $user->tenant_id === $employee->tenant_id
            && $user->can('employee_qualification.write')
            && ! $user->organizationalScopes()->exists();
    }
}
