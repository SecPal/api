<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
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
     *
     * Users with customers.read permission can list the full collection.
     * Users without that permission may only list the collection when they
     * already have scoped access to at least one customer via assignment or site access.
     */
    public function viewAny(User $user): bool
    {
        if ($user->can('customers.read') && ! $user->organizationalScopes()->exists()) {
            return true;
        }

        return $user->hasAccessibleCustomers();
    }

    /**
     * Determine whether the user can view the customer.
     *
     * Access is granted if:
     * - User has customers.read permission (can view any customer), OR
     * - User is directly assigned to the customer (any role), OR
     * - User has access to at least one site of this customer
     */
    public function view(User $user, Customer $customer): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $customer->tenant_id) {
            return false;
        }

        // Permission-based access
        if ($user->can('customers.read') && ! $user->organizationalScopes()->exists()) {
            return true;
        }

        if (! $customer->legalEntity()->exists()) {
            return false;
        }

        // Direct assignment to customer (must be currently active)
        if ($customer->assignments()->where('user_id', $user->id)->currentlyActive()->exists()) {
            return true;
        }

        // Sites where user is assigned directly (currently active only)
        $assignedSiteIds = $user->siteAssignments()->currentlyActive()->pluck('site_id')->toArray();

        return $customer->sites()
            ->whereIn('id', $assignedSiteIds)
            ->exists();
    }

    /**
     * Determine whether the user can create customers.
     */
    public function create(User $user): bool
    {
        return $user->can('customers.create')
            && ! $user->organizationalScopes()->exists();
    }

    /**
     * Determine whether the user can update the customer.
     *
     * Assigned users can update customer details.
     */
    public function update(User $user, Customer $customer): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $customer->tenant_id) {
            return false;
        }

        // Assigned users can update (must be currently active)
        if ($customer->assignments()->where('user_id', $user->id)->currentlyActive()->exists()) {
            return true;
        }

        return $user->can('customers.update');
    }

    /**
     * Determine whether the user can delete the customer.
     *
     * Requires customers.delete permission.
     * Business rule check (active sites) is handled in controller to return proper HTTP 409.
     */
    public function delete(User $user, Customer $customer): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $customer->tenant_id) {
            return false;
        }

        return $user->can('customers.delete');
    }
}
