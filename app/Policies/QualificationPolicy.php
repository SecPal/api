<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Qualification;
use App\Models\User;

/**
 * Qualification Policy
 *
 * Authorization rules for qualification management (system + custom).
 *
 * Rules:
 * - viewAny: All authenticated users (need to see available qualifications)
 * - view: All authenticated users
 * - create: HR only (for custom qualifications)
 * - update: HR only (cannot modify system qualifications)
 * - delete: HR only (cannot delete system qualifications)
 */
class QualificationPolicy
{
    /**
     * Determine if user can view any qualifications.
     *
     * Users with qualification.read permission can view qualifications.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('qualification.read');
    }

    /**
     * Determine if user can view a specific qualification.
     *
     * Users with qualification.read permission can view qualifications.
     */
    public function view(User $user, Qualification $qualification): bool
    {
        return $user->can('qualification.read');
    }

    /**
     * Determine if user can create qualifications.
     *
     * Users with qualification.write permission can create custom qualifications.
     */
    public function create(User $user): bool
    {
        return $user->can('qualification.write');
    }

    /**
     * Determine if user can update a qualification.
     *
     * Users with qualification.write permission can update custom qualifications only.
     * System qualifications cannot be updated.
     */
    public function update(User $user, Qualification $qualification): bool
    {
        // Cannot update system qualifications
        if ($qualification->is_system_qualification) {
            return false;
        }

        return $user->can('qualification.write');
    }

    /**
     * Determine if user can delete a qualification.
     *
     * Users with qualification.write permission can delete custom qualifications only.
     * System qualifications cannot be deleted.
     */
    public function delete(User $user, Qualification $qualification): bool
    {
        // Cannot delete system qualifications
        if ($qualification->is_system_qualification) {
            return false;
        }

        return $user->can('qualification.write');
    }
}
