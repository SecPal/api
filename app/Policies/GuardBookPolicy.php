<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\CustomerUserAccess;
use App\Models\CustomerUserObjectAccess;
use App\Models\GuardBook;
use App\Models\User;

/**
 * Authorization policy for GuardBook model.
 *
 * Controls access to guard books (event stream containers) based on user type:
 *
 * 1. Customer Users (Client role):
 *    - READ-ONLY access based on customer hierarchy + fine-grained allowed_actions
 *    - Must have 'read_guard_book' in allowed_actions for object-specific access
 *    - Default to allow if customer hierarchy access exists
 *
 * 2. Internal Employees (non-Client roles):
 *    - Access based on organizational unit scopes (via customer's managedBy)
 *    - Full CRUD access based on access level
 *
 * @see ADR-007 for customer hierarchy and access control design
 */
class GuardBookPolicy
{
    /**
     * Determine whether the user can view any guard books.
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
     * Determine whether the user can view the guard book.
     *
     * For customer users: Check customer hierarchy + fine-grained permissions.
     * For internal employees: Check organizational scope.
     */
    public function view(User $user, GuardBook $guardBook): bool
    {
        if ($user->hasRole('Client')) {
            return $this->viewAsCustomerUser($user, $guardBook);
        }

        return $this->hasInternalAccess($user, $guardBook, 'read');
    }

    /**
     * Determine whether the user can create guard books.
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
     * Determine whether the user can update the guard book.
     *
     * Customer users (Client role) have NO update permissions.
     */
    public function update(User $user, GuardBook $guardBook): bool
    {
        if ($user->hasRole('Client')) {
            return false;
        }

        return $this->hasInternalAccess($user, $guardBook, 'write');
    }

    /**
     * Determine whether the user can delete the guard book.
     *
     * Customer users (Client role) have NO delete permissions.
     */
    public function delete(User $user, GuardBook $guardBook): bool
    {
        if ($user->hasRole('Client')) {
            return false;
        }

        return $this->hasInternalAccess($user, $guardBook, 'admin');
    }

    /**
     * Determine whether the customer user can view the guard book.
     *
     * Customer users need:
     * 1. Access to the object (via customer hierarchy or fine-grained)
     * 2. If fine-grained access, 'read_guard_book' must be in allowed_actions
     */
    private function viewAsCustomerUser(User $user, GuardBook $guardBook): bool
    {
        $object = $guardBook->getParentObject();

        // First check fine-grained object access
        $objectAccess = CustomerUserObjectAccess::where('user_id', $user->id)
            ->where('object_id', $object->id)
            ->where('tenant_id', $object->tenant_id)
            ->first();

        if ($objectAccess !== null) {
            // User has fine-grained access - check if read_guard_book is allowed
            return $objectAccess->canPerformAction('read_guard_book');
        }

        // Check customer hierarchy access
        return $this->hasCustomerHierarchyAccess($user, $object);
    }

    /**
     * Check if user has customer hierarchy access to the object.
     */
    private function hasCustomerHierarchyAccess(User $user, \App\Models\SecPalObject $object): bool
    {
        $accesses = CustomerUserAccess::with('customer')->where('user_id', $user->id)->get();

        foreach ($accesses as $access) {
            // Skip if tenant doesn't match
            if ($access->tenant_id !== $object->tenant_id) {
                continue;
            }

            if ($access->include_descendants) {
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
                if ($access->customer_id === $object->customer_id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if internal employee has access to the guard book via organizational scope.
     */
    private function hasInternalAccess(User $user, GuardBook $guardBook, string $requiredLevel): bool
    {
        $object = $guardBook->getParentObject();
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
