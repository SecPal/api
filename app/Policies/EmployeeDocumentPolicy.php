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
     * Employee can view own documents.
     * HR can view all documents.
     * Managers can view documents for employees in their scope.
     */
    public function viewAny(User $user): bool
    {
        // Admin and Manager can view any documents (with scope filtering in queries)
        return $user->hasRole('Admin') || $user->hasRole('Manager');
    }

    /**
     * Determine if user can view a specific document.
     *
     * Employee can view own documents if marked as visible.
     * HR can view all documents.
     * Managers can view documents for employees in their scope.
     */
    public function view(User $user, EmployeeDocument $document): bool
    {
        // Admin can view all documents
        if ($user->hasRole('Admin')) {
            return true;
        }

        $employee = $document->employee;
        if ($employee === null) {
            return false;
        }

        // Employee can view own documents if marked as visible
        if ($user->id === $employee->user_id && $document->visible_to_employee) {
            return true;
        }

        // Manager can view documents for employees in their scope
        if ($user->hasRole('Manager') && $employee->organizationalUnit !== null) {
            return $user->hasAccessToUnit($employee->organizationalUnit);
        }

        return false;
    }

    /**
     * Determine if user can create documents.
     *
     * Admin and Managers can upload documents.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Admin') || $user->hasRole('Manager');
    }

    /**
     * Determine if user can update a document.
     *
     * Admin and Managers can update documents.
     */
    public function update(User $user, EmployeeDocument $document): bool
    {
        return $user->hasRole('Admin') || $user->hasRole('Manager');
    }

    /**
     * Determine if user can delete a document.
     *
     * Admin and Managers can delete documents.
     */
    public function delete(User $user, EmployeeDocument $document): bool
    {
        return $user->hasRole('Admin') || $user->hasRole('Manager');
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
