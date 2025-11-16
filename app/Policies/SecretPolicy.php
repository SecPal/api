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
        // Owner can always view
        if ($secret->owner_id === $user->id) {
            return true;
        }

        // TODO: Check if user has read+ permission via SecretShare
        // This will be implemented in Phase 3 (Sharing)
        return false;
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
        // Owner can always update
        if ($secret->owner_id === $user->id) {
            return true;
        }

        // TODO: Check if user has write+ permission via SecretShare
        // This will be implemented in Phase 3 (Sharing)
        return false;
    }

    /**
     * Determine whether the user can delete the secret.
     */
    public function delete(User $user, Secret $secret): bool
    {
        // Owner can always delete
        if ($secret->owner_id === $user->id) {
            return true;
        }

        // TODO: Check if user has admin permission via SecretShare
        // This will be implemented in Phase 3 (Sharing)
        return false;
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
