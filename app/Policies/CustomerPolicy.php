<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Customer;
use App\Models\CustomerUserAccess;
use App\Models\User;

/**
 * Authorization policy for Customer model.
 *
 * Controls access to customers based on user type:
 *
 * 1. Customer Users (Client role):
 *    - READ-ONLY access to their assigned customer hierarchy
 *    - Access determined by CustomerUserAccess records
 *    - Cannot see internal organizational structure (managedBy relationship)
 *
 * 2. Internal Employees (non-Client roles):
 *    - Access based on organizational unit scopes
 *    - Can see customers managed by their accessible organizational units
 *    - Full CRUD access based on access level (read/write/manage/admin)
 *
 * @see ADR-007 for customer hierarchy and access control design
 */
class CustomerPolicy
{
    /**
     * Determine whether the user can view any customers.
     *
     * For customer users: Requires at least one CustomerUserAccess record.
     * For internal employees: Requires at least one organizational scope.
     */
    public function viewAny(User $user): bool
    {
        if ($user->hasRole('Client')) {
            return CustomerUserAccess::where('user_id', $user->id)->exists();
        }

        // Internal employees need organizational scope
        return $user->organizationalScopes()->exists();
    }

    /**
     * Determine whether the user can view the customer.
     *
     * For customer users: Check customer hierarchy access via CustomerUserAccess.
     * For internal employees: Check if managing org unit is accessible.
     */
    public function view(User $user, Customer $customer): bool
    {
        if ($user->hasRole('Client')) {
            return $this->hasCustomerAccess($user, $customer);
        }

        return $this->hasInternalAccess($user, $customer, 'read');
    }

    /**
     * Determine whether the user can create customers.
     *
     * Customer users (Client role) have NO create permissions.
     * Internal employees need at least 'manage' access level on any org unit.
     */
    public function create(User $user): bool
    {
        if ($user->hasRole('Client')) {
            return false;
        }

        // Internal employees need at least manage access level somewhere
        return $user->organizationalScopes()
            ->whereIn('access_level', ['manage', 'admin'])
            ->exists();
    }

    /**
     * Determine whether the user can update the customer.
     *
     * Customer users (Client role) have NO update permissions.
     * Internal employees need at least 'write' access to the managing org unit.
     */
    public function update(User $user, Customer $customer): bool
    {
        if ($user->hasRole('Client')) {
            return false;
        }

        return $this->hasInternalAccess($user, $customer, 'write');
    }

    /**
     * Determine whether the user can delete the customer.
     *
     * Customer users (Client role) have NO delete permissions.
     * Internal employees need 'admin' access to the managing org unit.
     */
    public function delete(User $user, Customer $customer): bool
    {
        if ($user->hasRole('Client')) {
            return false;
        }

        return $this->hasInternalAccess($user, $customer, 'admin');
    }

    /**
     * Determine whether the user can restore the customer.
     *
     * Customer users (Client role) have NO restore permissions.
     * Internal employees need 'admin' access.
     */
    public function restore(User $user, Customer $customer): bool
    {
        if ($user->hasRole('Client')) {
            return false;
        }

        return $this->hasInternalAccess($user, $customer, 'admin');
    }

    /**
     * Determine whether the user can permanently delete the customer.
     *
     * Customer users (Client role) have NO forceDelete permissions.
     * Internal employees need 'admin' access.
     */
    public function forceDelete(User $user, Customer $customer): bool
    {
        if ($user->hasRole('Client')) {
            return false;
        }

        return $this->hasInternalAccess($user, $customer, 'admin');
    }

    /**
     * Determine whether the user can view the managedBy relationship.
     *
     * Customer users should NEVER see which internal org unit manages them.
     * Internal employees can see this if they have access to the customer.
     */
    public function viewManagedBy(User $user, Customer $customer): bool
    {
        if ($user->hasRole('Client')) {
            return false;
        }

        return $this->hasInternalAccess($user, $customer, 'read');
    }

    /**
     * Check if customer user has access to the given customer.
     *
     * Evaluates all CustomerUserAccess records for the user:
     * - Direct assignment: customer_id matches
     * - Hierarchical access: include_descendants=true and customer is a descendant
     */
    private function hasCustomerAccess(User $user, Customer $customer): bool
    {
        $accesses = CustomerUserAccess::where('user_id', $user->id)->get();

        foreach ($accesses as $access) {
            // Skip if tenant doesn't match (defense-in-depth)
            if ($access->tenant_id !== $customer->tenant_id) {
                continue;
            }

            if ($access->include_descendants) {
                // Check if customer is the assigned customer or a descendant
                if ($access->customer_id === $customer->id) {
                    return true;
                }

                // Load the access customer and check ancestry
                $accessCustomer = $access->customer;
                if ($accessCustomer !== null && $accessCustomer->isAncestorOf($customer)) {
                    return true;
                }
            } else {
                // Exact match only
                if ($access->customer_id === $customer->id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if internal employee has access to the customer via organizational scope.
     *
     * Access is determined by:
     * 1. The customer's managed_by_organizational_unit_id
     * 2. The user's organizational scope assignments
     * 3. The required access level (read/write/manage/admin)
     */
    private function hasInternalAccess(User $user, Customer $customer, string $requiredLevel): bool
    {
        $managingUnitId = $customer->managed_by_organizational_unit_id;

        if ($managingUnitId === null) {
            // Customer has no managing org unit - no internal access possible
            return false;
        }

        $managingUnit = $customer->managedBy;

        if ($managingUnit === null) {
            return false;
        }

        return $user->hasAccessToUnit($managingUnit, $requiredLevel);
    }
}
