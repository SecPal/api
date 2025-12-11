<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\User;

/**
 * Onboarding Form Submission Policy
 *
 * Authorization rules for onboarding form submissions with pre-contract status checks.
 *
 * Rules:
 * - viewAny: Employee (own submissions) OR HR
 * - view: Employee (own) OR HR
 * - create: Employee (pre-contract status)
 * - update: Employee (own, if status = draft) OR HR
 * - approve: HR only
 * - reject: HR only
 */
class OnboardingFormSubmissionPolicy
{
    /**
     * Determine if user can view any submissions.
     *
     * Employee can view own submissions.
     * Admin and Managers can view all submissions.
     */
    public function viewAny(User $user): bool
    {
        // Admin and Manager can view any (with filtering in queries)
        return $user->hasRole('Admin') || $user->hasRole('Manager');
    }

    /**
     * Determine if user can view a specific submission.
     *
     * Employee can view own submissions.
     * Admin can view all submissions.
     * Managers can view submissions for employees in their scope.
     */
    public function view(User $user, OnboardingFormSubmission $submission): bool
    {
        $employee = $submission->employee;
        if ($employee === null) {
            return false;
        }

        // Employee can view own submissions
        if ($user->id === $employee->user_id) {
            return true;
        }

        // Admin can view all
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Manager can view submissions for employees in their scope
        if ($user->hasRole('Manager') && $employee->organizationalUnit !== null) {
            return $user->hasAccessToUnit($employee->organizationalUnit);
        }

        return false;
    }

    /**
     * Determine if user can create submissions.
     *
     * Only pre-contract employees can create submissions.
     */
    public function create(User $user): bool
    {
        /** @var Employee|null $employee */
        $employee = $user->employee()->first();

        // User must have an employee record
        if ($employee === null) {
            return false;
        }

        // Only pre-contract employees can create submissions
        return $employee->status === 'pre_contract';
    }

    /**
     * Determine if user can update a submission.
     *
     * Employee can update own submissions if status is draft.
     * HR can update any submission.
     */
    public function update(User $user, OnboardingFormSubmission $submission): bool
    {
        // Admin can update any submission
        if ($user->hasRole('Admin')) {
            return true;
        }

        $employee = $submission->employee;
        if ($employee === null) {
            return false;
        }

        // Employee can update own submissions if status is draft
        if ($user->id === $employee->user_id && $submission->status === 'draft') {
            return true;
        }

        return false;
    }

    /**
     * Determine if user can approve a submission.
     *
     * Only HR can approve submissions.
     */
    public function approve(User $user, OnboardingFormSubmission $submission): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can reject a submission.
     *
     * Only HR can reject submissions.
     */
    public function reject(User $user, OnboardingFormSubmission $submission): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can delete a submission.
     *
     * Only Admin can delete submissions.
     */
    public function delete(User $user, OnboardingFormSubmission $submission): bool
    {
        return $user->hasRole('Admin');
    }
}
