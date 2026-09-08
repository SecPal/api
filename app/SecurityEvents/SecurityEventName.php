<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\SecurityEvents;

enum SecurityEventName: string
{
    case AuthenticationFailed = 'authentication.failed';
    case AuthenticationMfaFailed = 'authentication.mfa_failed';
    case AuthenticationPasskeyFailed = 'authentication.passkey_failed';
    case AuthenticationTokenRejected = 'authentication.token_rejected';
    case AuthenticationRateLimited = 'authentication.rate_limited';
    case PasswordResetTokenRejected = 'password_reset.token_rejected';
    case PasswordResetRateLimited = 'password_reset.rate_limited';
}
