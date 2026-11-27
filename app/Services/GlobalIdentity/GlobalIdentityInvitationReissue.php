<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

interface GlobalIdentityInvitationReissue extends GlobalIdentityOperationSubject
{
    /**
     * Revalidate and establish the replacement invitation in the owning domain
     * using the current database transaction. Return its authorized address
     * for a new invitee, or null for an existing user-bound invitation.
     */
    public function authorizeReplacement(
        GlobalIdentityOperationContext $previous,
        GlobalIdentityOperationContext $replacement,
    ): ?string;
}
