<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Person;
use App\Models\User;

/**
 * Policy for Person authorization.
 *
 * Uses Spatie Permission for permission checks.
 * Tenant context MUST be set before policy checks.
 */
class PersonPolicy
{
    /**
     * Determine if the user can view any persons.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('person.read');
    }

    /**
     * Determine if the user can view the person.
     */
    public function view(User $user, Person $person): bool
    {
        return $user->can('person.read');
    }

    /**
     * Determine if the user can create persons.
     */
    public function create(User $user): bool
    {
        return $user->can('person.create');
    }

    /**
     * Determine if the user can update the person.
     */
    public function update(User $user, Person $person): bool
    {
        return $user->can('person.update');
    }

    /**
     * Determine if the user can delete the person.
     */
    public function delete(User $user, Person $person): bool
    {
        return $user->can('person.delete');
    }
}
