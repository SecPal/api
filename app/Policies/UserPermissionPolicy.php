<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Policies;

use App\Models\User;

/**
 * Authorization policy for direct permission assignment to users.
 *
 * Rules:
 * - viewPermissions: User can view own, Admin can view all
 * - assignPermission: Admin only
 * - revokePermission: Admin only
 */
class UserPermissionPolicy
{
    /**
     * Determine whether the user can view permissions of the target user.
     *
     * Users can view their own permissions.
     * Admins can view any user's permissions.
     */
    public function viewPermissions(User $currentUser, User $targetUser): bool
    {
        // User can view own permissions
        if ($currentUser->id === $targetUser->id) {
            return true;
        }

        // Admin can view any user's permissions
        return $currentUser->hasRole('Admin');
    }

    /**
     * Determine whether the user can assign permissions to the target user.
     *
     * Only Admins can assign direct permissions.
     */
    public function assignPermission(User $currentUser, User $targetUser): bool
    {
        return $currentUser->hasRole('Admin');
    }

    /**
     * Determine whether the user can revoke permissions from the target user.
     *
     * Only Admins can revoke direct permissions.
     */
    public function revokePermission(User $currentUser, User $targetUser): bool
    {
        return $currentUser->hasRole('Admin');
    }
}
