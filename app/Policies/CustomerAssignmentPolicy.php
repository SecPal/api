<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Policies;

use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\User;

/**
 * Authorization policy for CustomerAssignment model.
 *
 * Assignments can be managed by users who can update the parent customer.
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#312 Policies for Customer, Site, and Assignment access control
 */
class CustomerAssignmentPolicy
{
    /**
     * Determine whether the user can view any assignments.
     *
     * Access requires ability to view the parent customer.
     */
    public function viewAny(User $user, Customer $customer): bool
    {
        return $user->can('view', $customer);
    }

    /**
     * Determine whether the user can view the assignment.
     */
    public function view(User $user, CustomerAssignment $assignment): bool
    {
        return $user->can('view', $assignment->customer);
    }

    /**
     * Determine whether the user can create assignments.
     *
     * Requires assignments.create permission AND ability to update the customer.
     */
    public function create(User $user, Customer $customer): bool
    {
        return $user->can('assignments.create') && $user->can('update', $customer);
    }

    /**
     * Determine whether the user can update the assignment.
     */
    public function update(User $user, CustomerAssignment $assignment): bool
    {
        return $user->can('assignments.update') && $user->can('update', $assignment->customer);
    }

    /**
     * Determine whether the user can delete the assignment.
     */
    public function delete(User $user, CustomerAssignment $assignment): bool
    {
        return $user->can('assignments.delete') && $user->can('update', $assignment->customer);
    }
}
