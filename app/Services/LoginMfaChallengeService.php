<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

class LoginMfaChallengeService
{
    public const LOGIN_CONTEXT_SESSION = 'session';

    public const LOGIN_CONTEXT_TOKEN = 'token';

    private const CACHE_PREFIX = 'mfa.login_challenges.';

    /**
     * Create and persist a new pending MFA login challenge.
     *
     * @return array{id: string, purpose: string, login_context: string, primary_method: string, available_methods: list<string>, expires_at: string}
     */
    public function create(User $user, string $loginContext, ?string $deviceName = null): array
    {
        $id = (string) Str::uuid();
        $expiresAt = CarbonImmutable::now()->addMinutes($this->challengeExpirationMinutes());

        $this->cache()->put($this->cacheKey($id), [
            'user_id' => $user->id,
            'login_context' => $loginContext,
            'device_name' => $deviceName,
        ], $expiresAt);

        return [
            'id' => $id,
            'purpose' => 'login',
            'login_context' => $loginContext,
            'primary_method' => 'totp',
            'available_methods' => $this->availableMethodsFor($user),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Retrieve a stored pending MFA login challenge.
     *
     * @return array{user_id: string, login_context: string, device_name: string|null}|null
     */
    public function find(string $challengeId): ?array
    {
        $challenge = $this->cache()->get($this->cacheKey($challengeId));

        if (! is_array($challenge)) {
            return null;
        }

        $userId = $challenge['user_id'] ?? null;
        $loginContext = $challenge['login_context'] ?? null;
        $deviceName = $challenge['device_name'] ?? null;

        if (! is_string($userId) || ! is_string($loginContext) || ($deviceName !== null && ! is_string($deviceName))) {
            return null;
        }

        return [
            'user_id' => $userId,
            'login_context' => $loginContext,
            'device_name' => $deviceName,
        ];
    }

    /**
     * Remove a pending MFA login challenge.
     */
    public function forget(string $challengeId): void
    {
        $this->cache()->forget($this->cacheKey($challengeId));
    }

    /**
     * @return list<string>
     */
    private function availableMethodsFor(User $user): array
    {
        $methods = ['totp'];

        if (config('two-factor.recovery.enabled') && $user->getRemainingTwoFactorRecoveryCodesCount() > 0) {
            $methods[] = 'recovery_code';
        }

        return $methods;
    }

    private function cache(): Repository
    {
        /** @var string|null $store */
        $store = config('two-factor.cache.store');

        return cache()->store($store);
    }

    private function cacheKey(string $challengeId): string
    {
        return self::CACHE_PREFIX.$challengeId;
    }

    private function challengeExpirationMinutes(): int
    {
        $minutes = config('two-factor.challenge.expiration_minutes', 10);

        return is_int($minutes) ? $minutes : 10;
    }
}
