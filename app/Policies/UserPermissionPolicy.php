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
 * - viewPermissions: User can view own, or needs 'permissions.read' permission
 * - assignPermission: Requires 'permissions.assign_direct' permission
 * - revokePermission: Requires 'permissions.revoke_direct' permission
 */
class UserPermissionPolicy
{
    /**
     * Determine whether the user can view permissions of the target user.
     *
     * Users can view their own permissions.
     * Users with 'permissions.read' can view any user's permissions.
     */
    public function viewPermissions(User $currentUser, User $targetUser): bool
    {
        // User can view own permissions
        if ($currentUser->id === $targetUser->id) {
            return true;
        }

        // Check permission
        return $currentUser->can('permissions.read');
    }

    /**
     * Determine whether the user can assign permissions to the target user.
     *
     * Requires 'permissions.assign_direct' permission.
     */
    public function assignPermission(User $currentUser, User $targetUser): bool
    {
        return $currentUser->can('permissions.assign_direct');
    }

    /**
     * Determine whether the user can revoke permissions from the target user.
     *
     * Requires 'permissions.revoke_direct' permission.
     */
    public function revokePermission(User $currentUser, User $targetUser): bool
    {
        return $currentUser->can('permissions.revoke_direct');
    }
}
