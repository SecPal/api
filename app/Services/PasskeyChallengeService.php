<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

class PasskeyChallengeService
{
    private const AUTHENTICATION_CACHE_PREFIX = 'passkeys.authentication.';

    private const REGISTRATION_CACHE_PREFIX = 'passkeys.registration.';

    /**
     * @param  array<string, mixed>  $publicKeyOptions
     * @return array{challenge_id: string, public_key: array<string, mixed>, mediation: string, expires_at: string}
     */
    public function createAuthenticationChallenge(array $publicKeyOptions, string $mediation): array
    {
        $id = (string) Str::uuid();
        $expiresAt = CarbonImmutable::now()->addMinutes($this->challengeExpirationMinutes());

        $this->cache()->put($this->authenticationCacheKey($id), [
            'public_key' => $publicKeyOptions,
        ], $expiresAt);

        return [
            'challenge_id' => $id,
            'public_key' => $publicKeyOptions,
            'mediation' => $mediation,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{public_key: array<string, mixed>}|null
     */
    public function findAuthenticationChallenge(string $challengeId): ?array
    {
        $challenge = $this->cache()->get($this->authenticationCacheKey($challengeId));

        if (! is_array($challenge) || ! isset($challenge['public_key']) || ! is_array($challenge['public_key'])) {
            return null;
        }

        return [
            'public_key' => $challenge['public_key'],
        ];
    }

    public function forgetAuthenticationChallenge(string $challengeId): void
    {
        $this->cache()->forget($this->authenticationCacheKey($challengeId));
    }

    /**
     * @param  array<string, mixed>  $publicKeyOptions
     * @return array{challenge_id: string, public_key: array<string, mixed>, expires_at: string}
     */
    public function createRegistrationChallenge(User $user, array $publicKeyOptions): array
    {
        $id = (string) Str::uuid();
        $expiresAt = CarbonImmutable::now()->addMinutes($this->challengeExpirationMinutes());

        $this->cache()->put($this->registrationCacheKey($id), [
            'user_id' => $user->id,
            'public_key' => $publicKeyOptions,
        ], $expiresAt);

        return [
            'challenge_id' => $id,
            'public_key' => $publicKeyOptions,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{user_id: string, public_key: array<string, mixed>}|null
     */
    public function findRegistrationChallenge(string $challengeId): ?array
    {
        $challenge = $this->cache()->get($this->registrationCacheKey($challengeId));

        if (! is_array($challenge)) {
            return null;
        }

        $userId = $challenge['user_id'] ?? null;
        $publicKey = $challenge['public_key'] ?? null;

        if (! is_string($userId) || ! is_array($publicKey)) {
            return null;
        }

        return [
            'user_id' => $userId,
            'public_key' => $publicKey,
        ];
    }

    public function forgetRegistrationChallenge(string $challengeId): void
    {
        $this->cache()->forget($this->registrationCacheKey($challengeId));
    }

    private function cache(): Repository
    {
        /** @var string|null $store */
        $store = config('passkeys.cache.store');

        return cache()->store($store);
    }

    private function challengeExpirationMinutes(): int
    {
        $minutes = config('passkeys.challenge_expiration_minutes', 10);

        return is_int($minutes) ? $minutes : 10;
    }

    private function authenticationCacheKey(string $challengeId): string
    {
        return self::AUTHENTICATION_CACHE_PREFIX.$challengeId;
    }

    private function registrationCacheKey(string $challengeId): string
    {
        return self::REGISTRATION_CACHE_PREFIX.$challengeId;
    }
}
