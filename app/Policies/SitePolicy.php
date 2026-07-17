<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Policies;

use App\Models\Customer;
use App\Models\Site;
use App\Models\User;

/**
 * Authorization policy for Site model.
 *
 * Implements Need-to-Know principle:
 * - Users can see sites they are assigned to OR
 * - Sites belonging to customers they are assigned to
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#312 Policies for Customer, Site, and Assignment access control
 */
class SitePolicy
{
    /**
     * Determine whether the user can view any sites.
     *
     * Users with sites.read permission can list the full collection.
     * Users without that permission may only list the collection when they
     * already have scoped access to at least one site via assignments, customer access,
     * or organizational scopes.
     */
    public function viewAny(User $user): bool
    {
        if ($user->can('sites.read')) {
            return true;
        }

        return $user->hasAccessibleSites();
    }

    /**
     * Determine whether the user can view the site.
     *
     * Access is granted if:
     * - User has sites.read permission (can view any site), OR
     * - User is directly assigned to the site, OR
     * - User is assigned to the site's customer (Key Accounts see all customer sites), OR
     */
    public function view(User $user, Site $site): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $site->tenant_id) {
            return false;
        }

        // Permission-based access
        if ($user->can('sites.read')) {
            return true;
        }

        $customer = $site->customer;
        if (! $customer instanceof Customer || ! $customer->legalEntity()->exists()) {
            return false;
        }

        // Direct assignment to site (must be currently active)
        if ($site->assignments()->where('user_id', $user->id)->currentlyActive()->exists()) {
            return true;
        }

        // Assignment to customer (Key Account sees all customer sites, must be currently active)
        if ($customer->assignments()->where('user_id', $user->id)->currentlyActive()->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create sites.
     */
    public function create(User $user): bool
    {
        return ! $user->organizationalScopes()->exists() && $user->can('sites.create');
    }

    /**
     * Determine whether the user can update the site.
     *
     * Assigned users can update site details.
     */
    public function update(User $user, Site $site): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $site->tenant_id) {
            return false;
        }

        // Assigned users can update (must be currently active)
        if ($site->assignments()->where('user_id', $user->id)->currentlyActive()->exists()) {
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
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $site->tenant_id) {
            return false;
        }

        if (! $user->can('sites.delete')) {
            return false;
        }

        // Cannot delete if has active cost centers
        return ! $site->costCenters()->where('is_active', true)->exists();
    }
}
