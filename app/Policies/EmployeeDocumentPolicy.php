<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

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
 * - create: HR only
 * - update: HR only
 * - delete: HR only
 */
class EmployeeDocumentPolicy
{
    /**
     * Determine if user can view any documents.
     *
     * Users with employee_document.read permission can view documents.
     * Scope-based filtering handled at controller level.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('employee_document.read');
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

        // Users with permission can view
        if ($user->can('employee_document.read')) {
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
     * Determine if user can create documents.
     *
     * Users with employee_document.write permission can upload documents.
     * Scope validation enforced at controller level.
     */
    public function create(User $user): bool
    {
        return $user->can('employee_document.write');
    }

    /**
     * Determine if user can update a document.
     *
     * Users with employee_document.write permission can update with scope validation.
     */
    public function update(User $user, EmployeeDocument $document): bool
    {
        if (! $user->can('employee_document.write')) {
            return false;
        }

        $employee = $document->employee;
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
     * Determine if user can delete a document.
     *
     * Users with employee_document.write permission can delete with scope validation.
     */
    public function delete(User $user, EmployeeDocument $document): bool
    {
        if (! $user->can('employee_document.write')) {
            return false;
        }

        $employee = $document->employee;
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
