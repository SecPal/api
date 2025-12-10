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
     * HR can view all submissions.
     */
    public function viewAny(User $user): bool
    {
        // Always allow - specific filtering happens in view()
        return true;
    }

    /**
     * Determine if user can view a specific submission.
     *
     * Employee can view own submissions.
     * HR can view all submissions.
     */
    public function view(User $user, OnboardingFormSubmission $submission): bool
    {
        // Employee can view own submissions
        if ($user->id === $submission->employee->user_id) {
            return true;
        }

        // HR can view all
        if ($user->hasRole('Admin')) {
            return true;
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
        $employee = $user->employee;

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
        // HR can update any submission
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Employee can update own submissions if status is draft
        if ($user->id === $submission->employee->user_id && $submission->status === 'draft') {
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
}
