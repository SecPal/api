<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Laragear\TwoFactor\Events\TwoFactorRecoveryCodesDepleted;

class MfaService
{
    private ?bool $twoFactorStorageAvailable = null;

    public function __construct(private ActivityLogService $activityLogService) {}

    public function isStorageAvailable(): bool
    {
        return $this->twoFactorStorageAvailable ??= Schema::hasTable('two_factor_authentications');
    }

    public function hasEnabledTwoFactor(User $user): bool
    {
        if (! $this->isStorageAvailable()) {
            return false;
        }

        $user->loadMissing('twoFactorAuth');

        return $user->hasTwoFactorEnabled();
    }

    public function hasPendingEnrollment(User $user): bool
    {
        if (! $this->isStorageAvailable()) {
            return false;
        }

        $user->loadMissing('twoFactorAuth');

        return $user->hasPendingTwoFactorEnrollment();
    }

    /**
     * Build the public MFA status payload for the authenticated user.
     *
     * @return array{enabled: bool, method: string|null, recovery_codes_remaining: int, recovery_codes_generated_at: string|null, enrolled_at: string|null}
     */
    public function buildStatusData(User $user): array
    {
        if (! $this->isStorageAvailable()) {
            return [
                'enabled' => false,
                'method' => null,
                'recovery_codes_remaining' => 0,
                'recovery_codes_generated_at' => null,
                'enrolled_at' => null,
            ];
        }

        $user->loadMissing('twoFactorAuth');
        $hasEnabledTwoFactor = $user->hasTwoFactorEnabled();
        /** @var \Laragear\TwoFactor\Models\TwoFactorAuthentication|null $twoFactorAuthentication */
        $twoFactorAuthentication = $user->getRelation('twoFactorAuth');

        return [
            'enabled' => $hasEnabledTwoFactor,
            'method' => $hasEnabledTwoFactor ? 'totp' : null,
            'recovery_codes_remaining' => $hasEnabledTwoFactor ? $user->getRemainingTwoFactorRecoveryCodesCount() : 0,
            'recovery_codes_generated_at' => $user->getTwoFactorRecoveryCodesGeneratedAt()?->format(DATE_ATOM),
            'enrolled_at' => $twoFactorAuthentication?->enabled_at?->format(DATE_ATOM),
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
            'expires_at' => $this->pendingEnrollmentExpiresAt($user)?->toIso8601String() ?? CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * Determine when the current pending enrollment expires.
     */
    public function pendingEnrollmentExpiresAt(User $user): ?CarbonImmutable
    {
        if (! $this->hasPendingEnrollment($user)) {
            return null;
        }

        /** @var \Laragear\TwoFactor\Models\TwoFactorAuthentication|null $twoFactorAuthentication */
        $twoFactorAuthentication = $user->getRelation('twoFactorAuth');

        if ($twoFactorAuthentication?->created_at === null) {
            return null;
        }

        return CarbonImmutable::instance($twoFactorAuthentication->created_at)
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
        if (! $this->hasEnabledTwoFactor($user) || $user->twoFactorAuth === null) {
            return false;
        }

        return match ($method) {
            'totp' => $user->twoFactorAuth->validateCode($code),
            'recovery_code' => $this->consumeRecoveryCode($user, $code),
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public function revealRecoveryCodes(User $user): array
    {
        if (! $this->hasEnabledTwoFactor($user)) {
            return [];
        }

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
        if (! $this->hasEnabledTwoFactor($user)) {
            return [];
        }

        /** @var list<string> $codes */
        $codes = $user->generateRecoveryCodes()
            ->pluck('code')
            ->values()
            ->all();

        return $codes;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        if (! $this->hasEnabledTwoFactor($user) || $user->twoFactorAuth === null) {
            return false;
        }

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
