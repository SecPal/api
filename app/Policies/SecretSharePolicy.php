<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Secret;
use App\Models\SecretShare;
use App\Models\User;

/**
 * Policy for SecretShare access control.
 *
 * Authorization Rules:
 * - Only secret owner can grant/revoke shares
 * - Only secret owner can list shares
 * - Future: Users with 'admin' permission on secret can also grant/revoke
 */
class SecretSharePolicy
{
    /**
     * Determine if user can view all shares for a secret.
     */
    public function viewAny(User $user, Secret $secret): bool
    {
        // Only owner can list shares
        return $secret->owner_id === $user->id;
    }

    /**
     * Determine if user can grant access to a secret.
     */
    public function create(User $user, Secret $secret): bool
    {
        // Only owner can grant access
        // TODO: Allow users with 'admin' permission on secret
        return $secret->owner_id === $user->id;
    }

    /**
     * Determine if user can revoke a share.
     */
    public function delete(User $user, Secret $secret, SecretShare $share): bool
    {
        // Only owner can revoke shares
        // TODO: Allow users with 'admin' permission on secret
        // Verify share belongs to this secret
        if ($share->secret_id !== $secret->id) {
            return false;
        }

        return $secret->owner_id === $user->id;
    }
}
