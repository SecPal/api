<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use App\Models\User;
use Closure;
use SensitiveParameter;

final readonly class GlobalIdentityAccounts
{
    public function __construct(private GlobalEmailRepository $email, private GlobalIdentityWorkingKeyRegistry $keys) {}

    public function find(#[SensitiveParameter] string $address): ?User
    {
        try {
            GlobalEmailAddress::parse($address);
        } catch (GlobalIdentityKeyException) {
            return null;
        }

        return $this->withLookupVersions(function (GlobalEmailLookupVersions $versions) use ($address): ?User {
            $identity = $this->email->findUserIdentity($address, $versions);

            return $identity === null ? null : User::query()->where('email_version', $identity['email_version'])
                ->find($identity['id']);
        });
    }

    public function throttleDigest(#[SensitiveParameter] string $address): string
    {
        try {
            $address = GlobalEmailAddress::parse($address)->normalized();
        } catch (GlobalIdentityKeyException) {
            $address = '';
        }

        return $this->keys->withActiveKey(
            GlobalIdentityWorkingKeyPurpose::AuthenticationRateLimit,
            static fn (#[SensitiveParameter] string $key): string => hash_hmac('sha256', $address, $key),
        );
    }

    public function create(#[SensitiveParameter] User $user, #[SensitiveParameter] string $address): User
    {
        return $this->withLookupVersions(
            fn (GlobalEmailLookupVersions $versions): User => User::query()->findOrFail($this->email->create($user, $address, $versions)),
        );
    }

    /**
     * @template T
     *
     * @param  Closure(GlobalEmailLookupVersions): T  $use
     * @return T
     */
    public function withLookupVersions(Closure $use): mixed
    {
        return $this->keys->withLookupVersions($use);
    }
}
