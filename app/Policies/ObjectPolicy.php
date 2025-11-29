<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\CustomerUserAccess;
use App\Models\CustomerUserObjectAccess;
use App\Models\SecPalObject;
use App\Models\User;

/**
 * Authorization policy for SecPalObject model.
 *
 * Controls access to objects (physical locations/sites) based on user type:
 *
 * 1. Customer Users (Client role):
 *    - READ-ONLY access to objects within their customer hierarchy
 *    - Access via CustomerUserAccess (customer hierarchy) OR CustomerUserObjectAccess (fine-grained)
 *
 * 2. Internal Employees (non-Client roles):
 *    - Access based on organizational unit scopes (via customer's managedBy)
 *    - Full CRUD access based on access level
 *
 * @see ADR-007 for customer hierarchy and access control design
 */
class ObjectPolicy
{
    /**
     * Determine whether the user can view any objects.
     *
     * For customer users: Requires CustomerUserAccess or CustomerUserObjectAccess.
     * For internal employees: Requires organizational scope.
     */
    public function viewAny(User $user): bool
    {
        if ($user->hasRole('Client')) {
            return CustomerUserAccess::where('user_id', $user->id)->exists()
                || CustomerUserObjectAccess::where('user_id', $user->id)->exists();
        }

        return $user->organizationalScopes()->exists();
    }

    /**
     * Determine whether the user can view the object.
     *
     * For customer users: Check customer hierarchy or fine-grained object access.
     * For internal employees: Check if managing org unit is accessible.
     */
    public function view(User $user, SecPalObject $object): bool
    {
        if ($user->hasRole('Client')) {
            return $this->hasCustomerUserAccess($user, $object);
        }

        return $this->hasInternalAccess($user, $object, 'read');
    }

    /**
     * Determine whether the user can create objects.
     *
     * Customer users (Client role) have NO create permissions.
     */
    public function create(User $user): bool
    {
        if ($user->hasRole('Client')) {
            return false;
        }

        return $user->organizationalScopes()
            ->whereIn('access_level', ['manage', 'admin'])
            ->exists();
    }

    /**
     * Determine whether the user can update the object.
     *
     * Customer users (Client role) have NO update permissions.
     */
    public function update(User $user, SecPalObject $object): bool
    {
        if ($user->hasRole('Client')) {
            return false;
        }

        return $this->hasInternalAccess($user, $object, 'write');
    }

    /**
     * Determine whether the user can delete the object.
     *
     * Customer users (Client role) have NO delete permissions.
     */
    public function delete(User $user, SecPalObject $object): bool
    {
        if ($user->hasRole('Client')) {
            return false;
        }

        return $this->hasInternalAccess($user, $object, 'admin');
    }

    /**
     * Check if customer user has access to the object.
     *
     * Access is granted via:
     * 1. CustomerUserAccess - customer hierarchy includes the object's customer
     * 2. CustomerUserObjectAccess - fine-grained object-level access
     */
    private function hasCustomerUserAccess(User $user, SecPalObject $object): bool
    {
        // Check customer hierarchy access
        $accesses = CustomerUserAccess::where('user_id', $user->id)->get();

        foreach ($accesses as $access) {
            // Skip if tenant doesn't match
            if ($access->tenant_id !== $object->tenant_id) {
                continue;
            }

            if ($access->include_descendants) {
                // Check if object's customer is the assigned customer or a descendant
                if ($access->customer_id === $object->customer_id) {
                    return true;
                }

                $accessCustomer = $access->customer;
                $objectCustomer = $object->customer;
                if ($accessCustomer !== null && $objectCustomer !== null
                    && $accessCustomer->isAncestorOf($objectCustomer)) {
                    return true;
                }
            } else {
                // Exact customer match only
                if ($access->customer_id === $object->customer_id) {
                    return true;
                }
            }
        }

        // Check fine-grained object access
        return CustomerUserObjectAccess::where('user_id', $user->id)
            ->where('object_id', $object->id)
            ->where('tenant_id', $object->tenant_id)
            ->exists();
    }

    /**
     * Check if internal employee has access to the object via organizational scope.
     *
     * Access is determined by the object's customer's managedBy org unit.
     */
    private function hasInternalAccess(User $user, SecPalObject $object, string $requiredLevel): bool
    {
        $customer = $object->customer;
        if ($customer === null) {
            return false;
        }

        $managingUnit = $customer->managedBy;
        if ($managingUnit === null) {
            return false;
        }

        return $user->hasAccessToUnit($managingUnit, $requiredLevel);
    }
}
