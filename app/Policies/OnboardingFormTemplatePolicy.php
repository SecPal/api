<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Employee;
use App\Models\OnboardingFormTemplate;
use App\Models\User;

/**
 * Onboarding Form Template Policy
 *
 * Authorization rules for onboarding form template management.
 *
 * Rules:
 * - viewAny: HR only
 * - view: HR only
 * - create: HR only (for custom templates)
 * - update: HR only (cannot modify system templates)
 * - delete: HR only (cannot delete system templates)
 */
class OnboardingFormTemplatePolicy
{
    /**
     * Determine if user can view any onboarding form templates.
     *
     * Pre-contract employees can view templates for their onboarding.
     * Users with onboarding.read permission can view templates.
     */
    public function viewAny(User $user): bool
    {
        // Pre-contract employees can view templates for their onboarding
        $employee = $user->employee;
        if ($employee && $employee->status === Employee::STATUS_PRE_CONTRACT) {
            return true;
        }

        return $user->can('onboarding.read');
    }

    /**
     * Determine if user can view a specific template.
     *
     * Pre-contract employees can view templates for their onboarding.
     * Users with onboarding.read permission can view templates.
     */
    public function view(User $user, OnboardingFormTemplate $template): bool
    {
        // Pre-contract employees can view templates for their onboarding
        $employee = $user->employee;
        if ($employee && $employee->status === Employee::STATUS_PRE_CONTRACT) {
            return true;
        }

        return $user->can('onboarding.read');
    }

    /**
     * Determine if user can create templates.
     *
     * Only users with onboarding_template.write or onboarding_template.create permission can create templates.
     */
    public function create(User $user): bool
    {
        return $user->can('onboarding_template.write') || $user->can('onboarding_template.create');
    }

    /**
     * Determine if user can update a template.
     *
     * Users with onboarding_template.write or onboarding_template.update permission can update custom templates only.
     * System templates cannot be updated.
     */
    public function update(User $user, OnboardingFormTemplate $template): bool
    {
        // Cannot update system templates
        if ($template->is_system_template) {
            return false;
        }

        return $user->can('onboarding_template.write') || $user->can('onboarding_template.update');
    }

    /**
     * Determine if user can delete a template.
     *
     * Users with onboarding_template.write or onboarding_template.delete permission can delete custom templates only.
     * System templates cannot be deleted.
     */
    public function delete(User $user, OnboardingFormTemplate $template): bool
    {
        // Cannot delete system templates
        if ($template->is_system_template) {
            return false;
        }

        return $user->can('onboarding_template.write') || $user->can('onboarding_template.delete');
    }
}
