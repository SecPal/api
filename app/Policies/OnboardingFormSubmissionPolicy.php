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
 * - uploadFile: Employee (own, with onboarding.write permission)
 * - approve: HR only
 * - reject: HR only
 */
class OnboardingFormSubmissionPolicy
{
    /**
     * Determine if user can view any submissions.
     *
     * Users with onboarding.read permission can view submissions.
     * Scope-based filtering handled at controller level.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('onboarding.read');
    }

    /**
     * Determine if user can view a specific submission.
     *
     * Employee can view own submissions.
     * Users with onboarding.read permission can view with scope checks.
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

        // Users with permission can view
        if (! $user->can('onboarding.read')) {
            return false;
        }

        // Check if user has organizational scopes (Manager role)
        $hasScopes = $user->organizationalScopes()->exists();

        if ($hasScopes && $employee->organizationalUnit !== null) {
            // Check organizational scope
            return $user->hasAccessToUnit($employee->organizationalUnit);
        }

        // No scopes = no access
        return false;
    }

    /**
     * Determine if user can create submissions.
     *
     * Users must have onboarding.write permission AND be pre-contract employees.
     */
    public function create(User $user): bool
    {
        // Must have permission
        if (! $user->can('onboarding.write')) {
            return false;
        }

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
     * Users with onboarding.update permission can update any submission.
     */
    public function update(User $user, OnboardingFormSubmission $submission): bool
    {
        // Users with onboarding.write permission can update any submission
        if ($user->can('onboarding.write')) {
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
     * Determine if user can upload an attachment for a submission.
     *
     * Uploads are restricted to the authenticated pre-contract employee's own
     * submission and still require the onboarding.write capability.
     */
    public function uploadFile(User $user, OnboardingFormSubmission $submission): bool
    {
        if (! $user->can('onboarding.write')) {
            return false;
        }

        $employee = $submission->employee;

        return $employee !== null && $user->id === $employee->user_id;
    }

    /**
     * Determine if user can approve a submission.
     *
     * Users with onboarding.approve permission can approve submissions.
     */
    public function approve(User $user, OnboardingFormSubmission $submission): bool
    {
        return $user->can('onboarding.approve');
    }

    /**
     * Determine if user can reject a submission.
     *
     * Users with onboarding.approve permission can reject submissions.
     */
    public function reject(User $user, OnboardingFormSubmission $submission): bool
    {
        return $user->can('onboarding.approve');
    }

    /**
     * Determine if user can delete a submission.
     *
     * Users with onboarding.delete permission can delete submissions.
     * Only Admin has this permission (Admin has onboarding.* wildcard).
     * Manager has onboarding.write but NOT onboarding.delete.
     */
    public function delete(User $user, OnboardingFormSubmission $submission): bool
    {
        return $user->can('onboarding.delete');
    }
}
