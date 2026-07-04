<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laragear\TwoFactor\Events\TwoFactorRecoveryCodesDepleted;

class MfaService
{
    public function __construct(private ActivityLogService $activityLogService) {}

    /**
     * Build the public MFA status payload for the authenticated user.
     *
     * @return array{enabled: bool, method: string|null, recovery_codes_remaining: int, recovery_codes_generated_at: string|null, enrolled_at: string|null}
     */
    public function buildStatusData(User $user): array
    {
        $user->loadMissing('twoFactorAuth');

        return [
            'enabled' => $user->hasTwoFactorEnabled(),
            'method' => $user->hasTwoFactorEnabled() ? 'totp' : null,
            'recovery_codes_remaining' => $user->hasTwoFactorEnabled() ? $user->getRemainingTwoFactorRecoveryCodesCount() : 0,
            'recovery_codes_generated_at' => \App\Support\ApiTimestamp::nullable($user->getTwoFactorRecoveryCodesGeneratedAt()),
            'enrolled_at' => \App\Support\ApiTimestamp::nullable($user->twoFactorAuth->enabled_at),
        ];
    }

    /**
     * Prepare a new pending TOTP enrollment for the user.
     *
     * @return array{issuer: string, account_name: string, manual_entry_key: string, otpauth_uri: string, expires_at: string}
     */
    public function prepareEnrollment(User $user): array
    {
        $secret = $user->createTwoFactorAuth();
        $user->refresh();

        return [
            'issuer' => $user->getTwoFactorIssuer(),
            'account_name' => $user->getTwoFactorUserIdentifier(),
            'manual_entry_key' => $secret->toString(),
            'otpauth_uri' => $secret->toUri(),
            'expires_at' => \App\Support\ApiTimestamp::nullable($this->pendingEnrollmentExpiresAt($user))
                ?? \App\Support\ApiTimestamp::format(CarbonImmutable::now()),
        ];
    }

    /**
     * Determine when the current pending enrollment expires.
     */
    public function pendingEnrollmentExpiresAt(User $user): ?CarbonImmutable
    {
        if (! $user->hasPendingTwoFactorEnrollment() || $user->twoFactorAuth->created_at === null) {
            return null;
        }

        return CarbonImmutable::instance($user->twoFactorAuth->created_at)
            ->addMinutes($this->pendingEnrollmentLifetimeMinutes());
    }

    /**
     * Determine if the current pending enrollment has expired.
     */
    public function pendingEnrollmentHasExpired(User $user): bool
    {
        return $this->pendingEnrollmentExpiresAt($user)?->isPast() ?? false;
    }

    /**
     * Confirm the current pending TOTP enrollment.
     */
    public function confirmPendingEnrollment(User $user, string $code): bool
    {
        return $user->confirmTwoFactorAuth($code);
    }

    /**
     * Verify a TOTP or recovery code for an enabled MFA enrollment.
     */
    public function verifyEnabledTwoFactorCode(User $user, string $method, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        return match ($method) {
            'totp' => $user->twoFactorAuth->validateCode($code),
            'recovery_code' => $this->consumeRecoveryCode($user, $code),
            default => false,
        };
    }

    /**
     * Determine whether a TOTP code was recently consumed by the anti-replay cache.
     */
    public function isTotpCodeRecentlyUsed(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        /** @var string|null $store */
        $store = config('two-factor.cache.store');

        return cache()->store($store)->has($this->buildTotpAntiReplayCacheKey($user, $code));
    }

    /**
     * Build the Laragear TwoFactor anti-replay cache key for a consumed TOTP code.
     *
     * This intentionally mirrors the upstream internal key format currently used by
     * Laragear TwoFactor's anti-replay cache implementation:
     * `{prefix}|{twoFactorAuth key}|{code}`.
     *
     * Review this method against the upstream package implementation whenever the
     * `laragear/two-factor` dependency is upgraded.
     */
    private function buildTotpAntiReplayCacheKey(User $user, string $code): string
    {
        /** @var string $prefix */
        $prefix = config('two-factor.cache.prefix', '2fa.code');
        /** @var string|int $key */
        $key = $user->twoFactorAuth->getKey();

        return $prefix.'|'.$key.'|'.$code;
    }

    /**
     * @return list<string>
     */
    public function revealRecoveryCodes(User $user): array
    {
        /** @var list<string> $codes */
        $codes = $user->getRecoveryCodes()
            ->pluck('code')
            ->values()
            ->all();

        return $codes;
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        /** @var list<string> $codes */
        $codes = $user->generateRecoveryCodes()
            ->pluck('code')
            ->values()
            ->all();

        return $codes;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        if (! $user->twoFactorAuth->setRecoveryCodeAsUsed($code)) {
            return false;
        }

        $user->twoFactorAuth->save();

        if (! $user->twoFactorAuth->containsUnusedRecoveryCodes()) {
            event(new TwoFactorRecoveryCodesDepleted($user));
            $this->activityLogService->logUserMfaEvent(
                $user,
                'mfa_recovery_codes_depleted',
                'Multi-factor recovery codes depleted',
                [
                    'recovery_codes_remaining' => 0,
                ]
            );
        }

        return true;
    }

    private function pendingEnrollmentLifetimeMinutes(): int
    {
        $minutes = config('two-factor.confirm.time', 180);

        return is_int($minutes) ? $minutes : 180;
    }
}
