<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
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
 * - viewAny: pre-contract employees (own submissions) OR users with onboarding.read
 * - view: Employee (own) OR HR
 * - create: pre-contract employees only (self-service)
 * - update: Employee (own; controller enforces editable states)
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
        if ($this->isPreContractEmployee($user)) {
            return true;
        }

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
     * Only pre-contract employees can create their own onboarding submissions
     * (self-service path). No additional onboarding.write permission is required.
     */
    public function create(User $user): bool
    {
        return $this->isPreContractEmployee($user);
    }

    /**
     * Determine if user can update a submission.
     *
     * Only the authenticated employee who owns the submission can update it.
     * The controller decides whether the current submission state is still editable.
     */
    public function update(User $user, OnboardingFormSubmission $submission): bool
    {
        $employee = $submission->employee;
        if ($employee === null) {
            return false;
        }

        return $user->id === $employee->user_id;
    }

    /**
     * Determine if user can upload an attachment for a submission.
     *
     * Uploads follow the self-service path for the authenticated employee's own
     * submission. Editable-state enforcement stays at the controller layer.
     */
    public function uploadFile(User $user, OnboardingFormSubmission $submission): bool
    {
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
     * Requires explicit onboarding delete authorization.
     * Manager has onboarding.write but NOT onboarding.delete.
     */
    public function delete(User $user, OnboardingFormSubmission $submission): bool
    {
        return $user->can('onboarding.delete');
    }

    private function isPreContractEmployee(User $user): bool
    {
        return Employee::query()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', $user->tenant_id)
            ->value('status') === Employee::STATUS_PRE_CONTRACT;
    }
}
