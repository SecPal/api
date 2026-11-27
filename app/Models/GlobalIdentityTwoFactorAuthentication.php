<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Casts\GlobalIdentityMfaMaterial;
use App\Exceptions\GlobalIdentityKeyException;
use App\Services\GlobalIdentity\GlobalIdentityEnvelopeCodec;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;
use JsonException;
use Laragear\TwoFactor\Models\TwoFactorAuthentication;

final class GlobalIdentityTwoFactorAuthentication extends TwoFactorAuthentication
{
    private const MATERIAL = ['shared_secret', 'recovery_codes'];

    protected $table = 'two_factor_authentications';

    protected $hidden = ['shared_secret', 'recovery_codes', 'safe_devices'];

    protected function casts(): array
    {
        return [
            'shared_secret' => GlobalIdentityMfaMaterial::class,
            'recovery_codes' => GlobalIdentityMfaMaterial::class,
        ];
    }

    /** @param array{touch?: bool|null} $options */
    public function save(array $options = []): bool
    {
        $dirty = array_values(array_intersect(self::MATERIAL, array_keys($this->getDirty())));
        if ($dirty === []) {
            return parent::save($options);
        }

        $keys = app(GlobalIdentityWorkingKeyRegistry::class);

        return $keys->withReadLease(function () use ($keys, $dirty, $options): bool {
            $this->prepareRotation($keys, $dirty, verify: false);

            return parent::save($options);
        });
    }

    public function reencryptMaterial(): bool
    {
        $keys = app(GlobalIdentityWorkingKeyRegistry::class);

        return $keys->withReadLease(function () use ($keys): bool {
            if (! $this->prepareRotation($keys, self::MATERIAL, verify: true)) {
                return false;
            }
            if (! self::withoutTimestamps(fn (): bool => $this->saveQuietly())) {
                throw new GlobalIdentityKeyException('MFA rotation write unavailable.');
            }

            return true;
        });
    }

    /** @param list<string> $fields */
    private function prepareRotation(GlobalIdentityWorkingKeyRegistry $keys, array $fields, bool $verify): bool
    {
        $purpose = GlobalIdentityWorkingKeyPurpose::MfaCredentials;
        $active = $keys->activeReference($purpose);
        $codec = app(GlobalIdentityEnvelopeCodec::class);
        $changed = false;
        foreach ($fields as $field) {
            $raw = $this->getAttributes()[$field] ?? null;
            if ($raw === null) {
                continue;
            }
            try {
                $envelope = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null;
                if (! is_array($envelope)) {
                    throw new GlobalIdentityKeyException('Invalid MFA credential envelope.');
                }
                $rewrap = $codec->reference($envelope, $purpose)->reference != $active;
            } catch (JsonException) {
                throw new GlobalIdentityKeyException('Invalid MFA credential envelope.');
            }
            if (! $rewrap && ! $verify) {
                continue;
            }
            $value = $this->getAttribute($field);
            try {
                if ($rewrap) {
                    $this->setAttribute($field, $value);
                    $changed = true;
                }
            } finally {
                if (is_string($value)) {
                    sodium_memzero($value);
                }
            }
        }

        return $changed;
    }
}
