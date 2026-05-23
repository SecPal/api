<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\PushDeviceRegistration;
use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

final class PushDeviceRegistrationService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{registration: PushDeviceRegistration, created: bool}
     */
    public function upsert(User $user, string $installationId, array $attributes): array
    {
        if ($user->tenant_id === null) {
            throw new RuntimeException('Authenticated user must belong to a tenant before registering a push device.');
        }

        /** @var array<string, mixed> $app */
        $app = is_array($attributes['app'] ?? null) ? $attributes['app'] : [];
        /** @var array<string, mixed> $device */
        $device = is_array($attributes['device'] ?? null) ? $attributes['device'] : [];
        /** @var array<string, mixed> $runtime */
        $runtime = is_array($attributes['runtime'] ?? null) ? $attributes['runtime'] : [];

        $registration = PushDeviceRegistration::query()->updateOrCreate(
            [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'installation_id' => $installationId,
            ],
            [
                'platform' => $this->requiredString($attributes, 'platform'),
                'provider' => $this->requiredString($attributes, 'provider'),
                'device_name' => $this->requiredString($attributes, 'device_name'),
                'push_token_plain' => $this->requiredString($attributes, 'push_token'),
                'last_lifecycle_event' => $this->requiredString($attributes, 'lifecycle_event'),
                'package_name' => $this->requiredString($app, 'package_name'),
                'package_version_name' => $this->nullableString($app, 'package_version_name'),
                'package_version_code' => $this->nullableInt($app, 'package_version_code'),
                'manufacturer' => $this->nullableString($device, 'manufacturer'),
                'model' => $this->nullableString($device, 'model'),
                'android_version' => $this->nullableString($device, 'android_version'),
                'sdk_int' => $this->nullableInt($device, 'sdk_int'),
                'bootstrap_version' => $this->requiredString($runtime, 'bootstrap_version'),
                'schema_version' => $this->requiredInt($runtime, 'schema_version'),
                'push_metadata_revision' => $this->requiredInt($runtime, 'push_metadata_revision'),
            ],
        );

        $created = $registration->wasRecentlyCreated;

        $freshRegistration = $registration->fresh();

        if (! $freshRegistration instanceof PushDeviceRegistration) {
            throw new RuntimeException('Unable to reload push device registration after save.');
        }

        return [
            'registration' => $freshRegistration,
            'created' => $created,
        ];
    }

    /**
     * @return array{installation_id: string, revoked_at: string}|null
     */
    public function revoke(User $user, string $installationId): ?array
    {
        if ($user->tenant_id === null) {
            return null;
        }

        $registration = PushDeviceRegistration::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('installation_id', $installationId)
            ->first();

        if ($registration === null) {
            return null;
        }

        $revokedAt = now();
        $registration->delete();

        return [
            'installation_id' => $installationId,
            'revoked_at' => $revokedAt->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Expected string value for "%s".', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function nullableString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Expected nullable string value for "%s".', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function requiredInt(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf('Expected integer value for "%s".', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function nullableInt(array $values, string $key): ?int
    {
        $value = $values[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf('Expected nullable integer value for "%s".', $key));
        }

        return $value;
    }
}
