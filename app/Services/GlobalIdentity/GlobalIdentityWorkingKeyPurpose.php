<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

enum GlobalIdentityWorkingKeyPurpose: string
{
    case EmailEncryption = 'secpal/global-identity/email-encryption/v1';
    case UserEmailLookup = 'secpal/global-identity/user-email-lookup/v1';
    case OperationDeliverySecret = 'secpal/global-identity/operation-delivery-secret/v1';
    case InvitationAddressEncryption = 'secpal/global-identity/invitation-address-encryption/v1';
    case PendingEmailChangeEncryption = 'secpal/global-identity/pending-email-change-encryption/v1';
    case MfaCredentials = 'secpal/global-identity/mfa-credentials/v1';

    public function algorithm(): string
    {
        return $this === self::UserEmailLookup ? 'HMAC-SHA-256' : 'XCHACHA20-POLY1305-IETF';
    }
}
