<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

enum GlobalIdentityOperationType: string
{
    case PasswordReset = 'password_reset';
    case EmailVerification = 'email_verification';
    case PendingEmailChange = 'pending_email_change';
    case Invitation = 'invitation';

    public function recipientPurpose(): ?GlobalIdentityWorkingKeyPurpose
    {
        return match ($this) {
            self::PendingEmailChange => GlobalIdentityWorkingKeyPurpose::PendingEmailChangeEncryption,
            self::Invitation => GlobalIdentityWorkingKeyPurpose::InvitationAddressEncryption,
            default => null,
        };
    }
}
