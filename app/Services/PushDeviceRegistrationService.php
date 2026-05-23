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

        $registration = PushDeviceRegistration::query()->firstOrNew([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'installation_id' => $installationId,
        ]);

        $created = ! $registration->exists;
        /** @var array<string, mixed> $app */
        $app = is_array($attributes['app'] ?? null) ? $attributes['app'] : [];
        /** @var array<string, mixed> $device */
        $device = is_array($attributes['device'] ?? null) ? $attributes['device'] : [];
        /** @var array<string, mixed> $runtime */
        $runtime = is_array($attributes['runtime'] ?? null) ? $attributes['runtime'] : [];

        $registration->tenant_id = $user->tenant_id;
        $registration->user_id = $user->id;
        $registration->installation_id = $installationId;
        $registration->platform = $this->requiredString($attributes, 'platform');
        $registration->provider = $this->requiredString($attributes, 'provider');
        $registration->device_name = $this->requiredString($attributes, 'device_name');
        $registration->push_token_plain = $this->requiredString($attributes, 'push_token');
        $registration->last_lifecycle_event = $this->requiredString($attributes, 'lifecycle_event');
        $registration->package_name = $this->requiredString($app, 'package_name');
        $registration->package_version_name = $this->nullableString($app, 'package_version_name');
        $registration->package_version_code = $this->nullableInt($app, 'package_version_code');
        $registration->manufacturer = $this->nullableString($device, 'manufacturer');
        $registration->model = $this->nullableString($device, 'model');
        $registration->android_version = $this->nullableString($device, 'android_version');
        $registration->sdk_int = $this->nullableInt($device, 'sdk_int');
        $registration->bootstrap_version = $this->requiredString($runtime, 'bootstrap_version');
        $registration->schema_version = $this->requiredInt($runtime, 'schema_version');
        $registration->push_metadata_revision = $this->requiredInt($runtime, 'push_metadata_revision');
        $registration->save();

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
