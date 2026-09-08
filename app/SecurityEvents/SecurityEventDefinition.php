<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\SecurityEvents;

use InvalidArgumentException;

final class SecurityEventDefinition
{
    private const AUTHENTICATION_METHOD = 'authentication_method';

    private const LOGIN_CONTEXT = 'login_context';

    private const RESET_PHASE = 'reset_phase';

    public static function outcome(SecurityEventName $name): SecurityEventOutcome
    {
        return match ($name) {
            SecurityEventName::AuthenticationFailed,
            SecurityEventName::AuthenticationMfaFailed,
            SecurityEventName::AuthenticationPasskeyFailed => SecurityEventOutcome::Failure,
            SecurityEventName::AuthenticationTokenRejected,
            SecurityEventName::AuthenticationRateLimited,
            SecurityEventName::PasswordResetTokenRejected,
            SecurityEventName::PasswordResetRateLimited => SecurityEventOutcome::Denied,
        };
    }

    public static function reason(SecurityEventName $name): SecurityEventReason
    {
        return match ($name) {
            SecurityEventName::AuthenticationFailed => SecurityEventReason::InvalidCredentials,
            SecurityEventName::AuthenticationMfaFailed => SecurityEventReason::InvalidMfaCode,
            SecurityEventName::AuthenticationPasskeyFailed => SecurityEventReason::InvalidPasskeyCredential,
            SecurityEventName::AuthenticationTokenRejected,
            SecurityEventName::PasswordResetTokenRejected => SecurityEventReason::InvalidOrExpired,
            SecurityEventName::AuthenticationRateLimited,
            SecurityEventName::PasswordResetRateLimited => SecurityEventReason::RateLimited,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function assertMetadata(SecurityEventName $name, array $metadata): void
    {
        $expectedKeys = match ($name) {
            SecurityEventName::AuthenticationFailed,
            SecurityEventName::AuthenticationMfaFailed,
            SecurityEventName::AuthenticationPasskeyFailed,
            SecurityEventName::AuthenticationRateLimited => [self::AUTHENTICATION_METHOD, self::LOGIN_CONTEXT],
            SecurityEventName::AuthenticationTokenRejected => [self::AUTHENTICATION_METHOD],
            SecurityEventName::PasswordResetTokenRejected,
            SecurityEventName::PasswordResetRateLimited => [self::RESET_PHASE],
        };

        $actualKeys = array_keys($metadata);
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException('Security event metadata does not match its event definition.');
        }

        $valid = match ($name) {
            SecurityEventName::AuthenticationFailed,
            SecurityEventName::AuthenticationRateLimited => $metadata[self::AUTHENTICATION_METHOD] === 'password'
                && in_array($metadata[self::LOGIN_CONTEXT], ['session', 'token'], true),
            SecurityEventName::AuthenticationMfaFailed => in_array(
                $metadata[self::AUTHENTICATION_METHOD],
                ['totp', 'recovery_code'],
                true,
            ) && in_array($metadata[self::LOGIN_CONTEXT], ['session', 'token'], true),
            SecurityEventName::AuthenticationPasskeyFailed => $metadata[self::AUTHENTICATION_METHOD] === 'passkey'
                && in_array($metadata[self::LOGIN_CONTEXT], ['session', 'token'], true),
            SecurityEventName::AuthenticationTokenRejected => $metadata[self::AUTHENTICATION_METHOD] === 'bearer_token',
            SecurityEventName::PasswordResetTokenRejected => $metadata[self::RESET_PHASE] === 'confirmation',
            SecurityEventName::PasswordResetRateLimited => in_array($metadata[self::RESET_PHASE], ['request', 'confirmation'], true),
        };

        if (! $valid) {
            throw new InvalidArgumentException('Security event metadata contains an unsupported value.');
        }
    }
}
