<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\SecurityEvents;

enum SecurityEventReason: string
{
    case InvalidCredentials = 'invalid_credentials';
    case InvalidMfaCode = 'invalid_mfa_code';
    case InvalidPasskeyCredential = 'invalid_passkey_credential';
    case InvalidOrExpired = 'invalid_or_expired';
    case RateLimited = 'rate_limited';
}
