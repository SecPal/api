<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Authorization policy for Customer model.
 *
 * Implements Need-to-Know principle:
 * - Users can only see customers they are assigned to OR
 * - Users with access to at least one site of the customer
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#312 Policies for Customer, Site, and Assignment access control
 */
class CustomerPolicy
{
    /**
     * Determine whether the user can view any customers.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('customers.read');
    }

    /**
     * Determine whether the user can view the customer.
     *
     * Access is granted if:
     * - User is directly assigned to the customer (any role), OR
     * - User has access to at least one site of this customer
     */
    public function view(User $user, Customer $customer): bool
    {
        // Direct assignment to customer (must be currently active)
        if ($customer->assignments()->where('user_id', $user->id)->currentlyActive()->exists()) {
            return true;
        }

        // Access via site (user has access to at least one site of this customer)
        $accessibleUnitIds = $user->getAccessibleOrganizationalUnitIds();

        // Sites where user is assigned directly (currently active only)
        $assignedSiteIds = $user->siteAssignments()->currentlyActive()->pluck('site_id')->toArray();

        return $customer->sites()
            ->where(function ($query) use ($accessibleUnitIds, $assignedSiteIds) {
                $query->whereIn('organizational_unit_id', $accessibleUnitIds)
                    ->orWhereIn('id', $assignedSiteIds);
            })
            ->exists();
    }

    /**
     * Determine whether the user can create customers.
     */
    public function create(User $user): bool
    {
        return $user->can('customers.create');
    }

    /**
     * Determine whether the user can update the customer.
     *
     * Assigned users can update customer details.
     */
    public function update(User $user, Customer $customer): bool
    {
        // Assigned users can update (must be currently active)
        if ($customer->assignments()->where('user_id', $user->id)->currentlyActive()->exists()) {
            return true;
        }

        return $user->can('customers.update');
    }

    /**
     * Determine whether the user can delete the customer.
     *
     * Requires permission AND no active sites exist.
     */
    public function delete(User $user, Customer $customer): bool
    {
        if (! $user->can('customers.delete')) {
            return false;
        }

        // Cannot delete if has active sites
        return ! $customer->sites()->where('is_active', true)->exists();
    }
}
