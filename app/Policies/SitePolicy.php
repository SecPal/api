<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

/**
 * Authorization policy for Site model.
 *
 * Implements Need-to-Know principle:
 * - Users can see sites they are assigned to OR
 * - Sites in their accessible organizational units OR
 * - Sites belonging to customers they are assigned to
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#312 Policies for Customer, Site, and Assignment access control
 */
class SitePolicy
{
    /**
     * Determine whether the user can view any sites.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('sites.read');
    }

    /**
     * Determine whether the user can view the site.
     *
     * Access is granted if:
     * - User is directly assigned to the site, OR
     * - User is assigned to the site's customer (Key Accounts see all customer sites), OR
     * - User has access to the site's organizational unit
     */
    public function view(User $user, Site $site): bool
    {
        // Direct assignment to site
        if ($site->assignments()->where('user_id', $user->id)->exists()) {
            return true;
        }

        // Assignment to customer (Key Account sees all customer sites)
        if ($site->customer->assignments()->where('user_id', $user->id)->exists()) {
            return true;
        }

        // Access via organizational unit
        $accessibleUnitIds = $user->getAccessibleOrganizationalUnitIds();

        return in_array($site->organizational_unit_id, $accessibleUnitIds, true);
    }

    /**
     * Determine whether the user can create sites.
     */
    public function create(User $user): bool
    {
        return $user->can('sites.create');
    }

    /**
     * Determine whether the user can update the site.
     *
     * Assigned users can update site details.
     */
    public function update(User $user, Site $site): bool
    {
        // Assigned users can update
        if ($site->assignments()->where('user_id', $user->id)->exists()) {
            return true;
        }

        return $user->can('sites.update');
    }

    /**
     * Determine whether the user can delete the site.
     *
     * Requires permission AND no active cost centers exist.
     */
    public function delete(User $user, Site $site): bool
    {
        if (! $user->can('sites.delete')) {
            return false;
        }

        // Cannot delete if has active cost centers
        return ! $site->costCenters()->where('is_active', true)->exists();
    }
}
