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
}
