<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\User;

/**
 * Authorization policy for SiteAssignment model.
 *
 * Assignments can be managed by users who can update the parent site.
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#312 Policies for Customer, Site, and Assignment access control
 */
class SiteAssignmentPolicy
{
    /**
     * Determine whether the user can view any assignments.
     *
     * Access requires ability to view the parent site.
     */
    public function viewAny(User $user, Site $site): bool
    {
        return $user->can('view', $site);
    }

    /**
     * Determine whether the user can view the assignment.
     */
    public function view(User $user, SiteAssignment $assignment): bool
    {
        return $user->can('view', $assignment->site);
    }

    /**
     * Determine whether the user can create assignments.
     *
     * Requires assignments.create permission AND ability to update the site.
     */
    public function create(User $user, Site $site): bool
    {
        return $user->can('assignments.create') && $user->can('update', $site);
    }

    /**
     * Determine whether the user can update the assignment.
     */
    public function update(User $user, SiteAssignment $assignment): bool
    {
        return $user->can('assignments.update') && $user->can('update', $assignment->site);
    }

    /**
     * Determine whether the user can delete the assignment.
     */
    public function delete(User $user, SiteAssignment $assignment): bool
    {
        return $user->can('assignments.delete') && $user->can('update', $assignment->site);
    }
}
