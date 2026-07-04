<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

namespace App\Policies;

use App\Models\User;

class UserMfaPolicy
{
    /**
     * Determine whether the current user may administratively reset MFA for the target user.
     */
    public function resetMfa(User $currentUser, User $targetUser): bool
    {
        if (! $currentUser->sharesTenantWith($targetUser)) {
            return false;
        }

        if ($currentUser->is($targetUser)) {
            return false;
        }

        return $currentUser->can('users.reset_mfa');
    }
}
