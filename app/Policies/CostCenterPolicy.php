<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\CostCenter;
use App\Models\User;

/**
 * Authorization policy for CostCenter model.
 *
 * Cost centers inherit access control from their parent site.
 * If user can access the site, they can access its cost centers.
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#312 Policies for Customer, Site, and Assignment access control
 */
class CostCenterPolicy
{
    /**
     * Determine whether the user can view any cost centers.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('cost-centers.read');
    }

    /**
     * Determine whether the user can view the cost center.
     *
     * Access is granted if user can view the parent site.
     */
    public function view(User $user, CostCenter $costCenter): bool
    {
        $site = $costCenter->site;

        // Use SitePolicy to check access
        return $user->can('view', $site);
    }

    /**
     * Determine whether the user can create cost centers.
     *
     * Requires permission AND ability to update the parent site.
     */
    public function create(User $user, \App\Models\Site $site): bool
    {
        return $user->can('cost-centers.create') && $user->can('update', $site);
    }

    /**
     * Determine whether the user can update the cost center.
     *
     * Requires permission AND ability to update the parent site.
     */
    public function update(User $user, CostCenter $costCenter): bool
    {
        return $user->can('cost-centers.update') && $user->can('update', $costCenter->site);
    }

    /**
     * Determine whether the user can delete the cost center.
     *
     * Requires permission AND no active orders reference it.
     * (Order validation would happen in controller layer)
     */
    public function delete(User $user, CostCenter $costCenter): bool
    {
        return $user->can('cost-centers.delete') && $user->can('update', $costCenter->site);
    }
}
