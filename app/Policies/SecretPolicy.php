<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Secret;
use App\Models\User;

/**
 * Authorization policy for Secret model.
 *
 * Controls access to secrets based on ownership and share permissions.
 */
class SecretPolicy
{
    /**
     * Determine whether the user can view any secrets.
     */
    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can list their secrets
    }

    /**
     * Determine whether the user can view the secret.
     */
    public function view(User $user, Secret $secret): bool
    {
        // Check via userHasPermission (handles owner + share access)
        return $secret->userHasPermission($user, 'read');
    }

    /**
     * Determine whether the user can create secrets.
     */
    public function create(User $user): bool
    {
        return true; // All authenticated users can create secrets
    }

    /**
     * Determine whether the user can update the secret.
     */
    public function update(User $user, Secret $secret): bool
    {
        // Check via userHasPermission (handles owner + share access)
        return $secret->userHasPermission($user, 'write');
    }

    /**
     * Determine whether the user can delete the secret.
     */
    public function delete(User $user, Secret $secret): bool
    {
        // Check via userHasPermission (handles owner + share access)
        return $secret->userHasPermission($user, 'admin');
    }

    /**
     * Determine whether the user can restore the secret.
     */
    public function restore(User $user, Secret $secret): bool
    {
        return $secret->owner_id === $user->id;
    }

    /**
     * Determine whether the user can permanently delete the secret.
     */
    public function forceDelete(User $user, Secret $secret): bool
    {
        return $secret->owner_id === $user->id;
    }
}
