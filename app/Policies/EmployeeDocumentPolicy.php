<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Policies;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;

/**
 * Employee Document Policy
 *
 * Authorization rules for employee document management with visibility control.
 *
 * Rules:
 * - viewAny: Employee (own documents) OR HR OR Manager (scope)
 * - view: Check visible_to_employee flag + ownership
 * - create: employee_document.write with tenant-wide or scoped access
 * - update: employee_document.write with tenant-wide or scoped access
 * - delete: employee_document.write with tenant-wide or scoped access
 */
class EmployeeDocumentPolicy
{
    private function canManageEmployeeDocuments(User $user, Employee $employee, string $permission): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        if (! $user->organizationalScopes()->exists()) {
            return true;
        }

        if ($employee->organizationalUnit === null) {
            return false;
        }

        return $user->hasAccessToUnit($employee->organizationalUnit);
    }

    /**
     * Determine if user can view any documents.
     *
     * Employees can list their own visible documents.
     * Users with employee_document.read permission can list documents in scope
     * or tenant-wide when no scopes are assigned.
     */
    public function viewAny(User $user, Employee $employee): bool
    {
        if ($user->id === $employee->user_id) {
            return true;
        }

        return $this->canManageEmployeeDocuments($user, $employee, 'employee_document.read');
    }

    /**
     * Determine if user can view a specific document.
     *
     * Employee can view own documents if marked as visible.
     * Users with employee_document.read permission can view with scope checks.
     */
    public function view(User $user, EmployeeDocument $document): bool
    {
        $employee = $document->employee;
        if ($employee === null) {
            return false;
        }

        // Employee can view own documents if marked as visible
        if ($user->id === $employee->user_id && $document->visible_to_employee) {
            return true;
        }

        return $this->canManageEmployeeDocuments($user, $employee, 'employee_document.read');
    }

    /**
     * Determine if user can create documents.
     *
     * Users with employee_document.write permission can upload documents in
     * scope or tenant-wide when no scopes are assigned.
     */
    public function create(User $user, Employee $employee): bool
    {
        return $this->canManageEmployeeDocuments($user, $employee, 'employee_document.write');
    }

    /**
     * Determine if user can update a document.
     *
     * Users with employee_document.write permission can update with scope validation.
     */
    public function update(User $user, EmployeeDocument $document): bool
    {
        $employee = $document->employee;
        if ($employee === null) {
            return false;
        }

        return $this->canManageEmployeeDocuments($user, $employee, 'employee_document.write');
    }

    /**
     * Determine if user can delete a document.
     *
     * Users with employee_document.write permission can delete with scope validation.
     */
    public function delete(User $user, EmployeeDocument $document): bool
    {
        $employee = $document->employee;
        if ($employee === null) {
            return false;
        }

        return $this->canManageEmployeeDocuments($user, $employee, 'employee_document.write');
    }

    /**
     * Determine if user can download a document.
     *
     * Same rules as view.
     */
    public function download(User $user, EmployeeDocument $document): bool
    {
        return $this->view($user, $document);
    }
}
