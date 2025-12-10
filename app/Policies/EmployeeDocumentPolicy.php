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
        // Always allow - specific filtering happens in view()
        return true;
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
        // HR can view all documents
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Employee can view own documents if marked as visible
        if ($user->id === $document->employee->user_id && $document->visible_to_employee) {
            return true;
        }

        // Manager can view documents for employees in their scope
        if ($user->hasRole('Manager') && $document->employee->organizationalUnit !== null) {
            return $user->hasAccessToUnit($document->employee->organizationalUnit);
        }

        return false;
    }

    /**
     * Determine if user can create documents.
     *
     * Only HR can upload documents.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can update a document.
     *
     * Only HR can update documents.
     */
    public function update(User $user, EmployeeDocument $document): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can delete a document.
     *
     * Only HR can delete documents.
     */
    public function delete(User $user, EmployeeDocument $document): bool
    {
        return $user->hasRole('Admin');
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
