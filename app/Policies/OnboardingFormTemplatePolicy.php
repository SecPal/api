<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

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
     * Only HR can view templates.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can view a specific template.
     *
     * Only HR can view templates.
     */
    public function view(User $user, OnboardingFormTemplate $template): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can create templates.
     *
     * Only HR can create custom templates.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can update a template.
     *
     * HR can update custom templates only.
     * System templates cannot be updated.
     */
    public function update(User $user, OnboardingFormTemplate $template): bool
    {
        // Cannot update system templates
        if ($template->is_system_template) {
            return false;
        }

        return $user->hasRole('Admin');
    }

    /**
     * Determine if user can delete a template.
     *
     * HR can delete custom templates only.
     * System templates cannot be deleted.
     */
    public function delete(User $user, OnboardingFormTemplate $template): bool
    {
        // Cannot delete system templates
        if ($template->is_system_template) {
            return false;
        }

        return $user->hasRole('Admin');
    }
}
