<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Casts;

use App\Exceptions\GlobalIdentityKeyException;
use App\Services\GlobalIdentity\GlobalIdentityEnvelopeCodec;
use App\Services\GlobalIdentity\GlobalIdentityOperationContext;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyContext;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonException;
use SensitiveParameter;

/** @implements CastsAttributes<string|Collection<array-key, mixed>, string|Collection<array-key, mixed>> */
final class GlobalIdentityMfaMaterial implements CastsAttributes
{
    public bool $withoutObjectCaching = true;

    /** @return string|Collection<array-key, mixed>|null */
    public function get(Model $model, string $key, #[SensitiveParameter] mixed $value, array $attributes): string|Collection|null
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new GlobalIdentityKeyException('Invalid MFA credential envelope.');
        }
        $binding = $this->binding($key, $attributes);
        $codec = app(GlobalIdentityEnvelopeCodec::class);
        try {
            $envelope = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($envelope)) {
                throw new GlobalIdentityKeyException('Invalid MFA credential envelope.');
            }
            $context = $codec->reference($envelope, GlobalIdentityWorkingKeyPurpose::MfaCredentials);
            $plaintext = app(GlobalIdentityWorkingKeyRegistry::class)->withKey(
                $context,
                static fn (#[SensitiveParameter] string $workingKey): string => $codec->open(
                    $envelope, GlobalIdentityWorkingKeyPurpose::MfaCredentials, $workingKey, $binding,
                ),
            );
            if ($key === 'shared_secret') {
                return $plaintext;
            }
            try {
                $codes = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($codes) || ! array_is_list($codes)) {
                    throw new GlobalIdentityKeyException('Invalid MFA recovery data.');
                }

                return collect($codes);
            } finally {
                sodium_memzero($plaintext);
            }
        } catch (JsonException) {
            throw new GlobalIdentityKeyException('Invalid MFA credential envelope.');
        }
    }

    /** @param string|Collection<array-key, mixed>|null $value */
    public function set(Model $model, string $key, #[SensitiveParameter] mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        $binding = $this->binding($key, $attributes);
        if (! is_string($value) && ! $value instanceof Collection) {
            throw new GlobalIdentityKeyException('Invalid MFA credential.');
        }
        $plaintext = $value instanceof Collection ? $value->toJson() : $value;
        try {
            return app(GlobalIdentityWorkingKeyRegistry::class)->withActiveKey(
                GlobalIdentityWorkingKeyPurpose::MfaCredentials,
                static fn (#[SensitiveParameter] string $workingKey, GlobalIdentityWorkingKeyContext $context): string => json_encode(
                    app(GlobalIdentityEnvelopeCodec::class)->seal($plaintext, $context, $workingKey, $binding),
                    JSON_THROW_ON_ERROR,
                ),
            );
        } finally {
            sodium_memzero($plaintext);
        }
    }

    /** @param array<string, mixed> $attributes
     * @return list<string>
     */
    private function binding(string $key, array $attributes): array
    {
        $id = $attributes['authenticatable_id'] ?? null;
        if (! is_string($id) || ! GlobalIdentityOperationContext::isCanonicalId($id)) {
            throw new GlobalIdentityKeyException('Invalid MFA account binding.');
        }

        return ['user', $id, $key];
    }
}
