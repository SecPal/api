<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\PushDeviceRegistration;
use App\Models\User;
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
        $app = is_array($attributes['app'] ?? null) ? $attributes['app'] : [];
        $device = is_array($attributes['device'] ?? null) ? $attributes['device'] : [];
        $runtime = is_array($attributes['runtime'] ?? null) ? $attributes['runtime'] : [];

        $registration->tenant_id = $user->tenant_id;
        $registration->user_id = $user->id;
        $registration->installation_id = $installationId;
        $registration->platform = (string) $attributes['platform'];
        $registration->provider = (string) $attributes['provider'];
        $registration->device_name = (string) $attributes['device_name'];
        $registration->push_token_plain = (string) $attributes['push_token'];
        $registration->last_lifecycle_event = (string) $attributes['lifecycle_event'];
        $registration->package_name = (string) ($app['package_name'] ?? '');
        $registration->package_version_name = is_string($app['package_version_name'] ?? null)
            ? $app['package_version_name']
            : null;
        $registration->package_version_code = is_int($app['package_version_code'] ?? null)
            ? $app['package_version_code']
            : null;
        $registration->manufacturer = is_string($device['manufacturer'] ?? null)
            ? $device['manufacturer']
            : null;
        $registration->model = is_string($device['model'] ?? null)
            ? $device['model']
            : null;
        $registration->android_version = is_string($device['android_version'] ?? null)
            ? $device['android_version']
            : null;
        $registration->sdk_int = is_int($device['sdk_int'] ?? null)
            ? $device['sdk_int']
            : null;
        $registration->bootstrap_version = (string) ($runtime['bootstrap_version'] ?? '');
        $registration->schema_version = (int) ($runtime['schema_version'] ?? 0);
        $registration->push_metadata_revision = (int) ($runtime['push_metadata_revision'] ?? 0);
        $registration->save();

        return [
            'registration' => $registration->fresh(),
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
}
