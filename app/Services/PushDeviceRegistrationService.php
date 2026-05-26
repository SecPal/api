<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\PushDeviceRegistration;
use App\Models\User;
use App\Support\BootstrapContract;
use Illuminate\Support\Carbon;
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

        $runtime = $this->requiredArray($attributes, 'runtime');
        $channel = $this->requiredString($attributes, 'channel');
        $registrationPayload = $this->requiredArray($attributes, 'registration');

        $registration = PushDeviceRegistration::query()->firstOrNew([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'installation_id' => $installationId,
        ]);

        $created = ! $registration->exists;

        $registration->tenant_id = $user->tenant_id;
        $registration->user_id = $user->id;
        $registration->installation_id = $installationId;
        $registration->device_name = $this->requiredString($attributes, 'installation_name');
        $registration->last_lifecycle_event = $this->requiredString($attributes, 'lifecycle_event');
        $registration->bootstrap_version = $this->requiredString($runtime, 'bootstrap_version');
        $registration->schema_version = $this->requiredInt($runtime, 'schema_version');
        $registration->push_metadata_revision = $this->requiredInt($runtime, 'metadata_revision');

        match ($channel) {
            BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM => $this->fillAndroidFcmRegistration($registration, $registrationPayload),
            BootstrapContract::NOTIFICATION_CHANNEL_WEB_PUSH => $this->fillWebPushRegistration($registration, $registrationPayload),
            default => throw new InvalidArgumentException(sprintf('Unsupported notification installation channel "%s".', $channel)),
        };

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
        $channel = $registration->notificationChannel();
        $registration->delete();

        return [
            'installation_id' => $installationId,
            'channel' => $channel,
            'revoked_at' => $revokedAt->utc()->format('Y-m-d\\TH:i:s\\Z'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $registrationPayload
     */
    private function fillAndroidFcmRegistration(PushDeviceRegistration $registration, array $registrationPayload): void
    {
        $app = $this->requiredArray($registrationPayload, 'app');
        $device = is_array($registrationPayload['device'] ?? null) ? $registrationPayload['device'] : [];

        $registration->platform = BootstrapContract::CLIENT_PLATFORM_ANDROID;
        $registration->provider = BootstrapContract::ANDROID_PUSH_PROVIDER;
        $registration->push_token_plain = $this->requiredString($registrationPayload, 'push_token');
        $registration->package_name = $this->requiredString($app, 'package_name');
        $registration->package_version_name = $this->nullableString($app, 'package_version_name');
        $registration->package_version_code = $this->nullableInt($app, 'package_version_code');
        $registration->manufacturer = $this->nullableString($device, 'manufacturer');
        $registration->model = $this->nullableString($device, 'model');
        $registration->android_version = $this->nullableString($device, 'android_version');
        $registration->sdk_int = $this->nullableInt($device, 'sdk_int');
        $registration->browser_name = null;
        $registration->browser_version = null;
        $registration->service_worker_scope = null;
        $registration->subscription_endpoint_origin = null;
        $registration->subscription_expires_at = null;
        $registration->subscription_p256dh_enc = null;
        $registration->subscription_auth_enc = null;
    }

    /**
     * @param  array<array-key, mixed>  $registrationPayload
     */
    private function fillWebPushRegistration(PushDeviceRegistration $registration, array $registrationPayload): void
    {
        $browser = $this->requiredArray($registrationPayload, 'browser');
        $subscription = $this->requiredArray($registrationPayload, 'subscription');
        $subscriptionKeys = $this->requiredArray($subscription, 'keys');
        $endpoint = $this->requiredString($subscription, 'endpoint');

        $registration->platform = BootstrapContract::CLIENT_PLATFORM_BROWSER;
        $registration->provider = BootstrapContract::WEB_PUSH_PROVIDER;
        $registration->push_token_enc = $endpoint;
        $registration->token_last_eight = substr(hash('sha256', $endpoint), 0, 8);
        $registration->package_name = null;
        $registration->package_version_name = null;
        $registration->package_version_code = null;
        $registration->manufacturer = null;
        $registration->model = null;
        $registration->android_version = null;
        $registration->sdk_int = null;
        $registration->browser_name = $this->requiredString($browser, 'browser_name');
        $registration->browser_version = $this->nullableString($browser, 'browser_version');
        $registration->service_worker_scope = $this->nullableString($browser, 'service_worker_scope');
        $registration->subscription_endpoint_origin = $this->subscriptionEndpointOrigin($endpoint);
        $registration->subscription_expires_at = $this->subscriptionExpirationTimestamp($subscription);
        $registration->subscription_p256dh_enc = $this->requiredString($subscriptionKeys, 'p256dh');
        $registration->subscription_auth_enc = $this->requiredString($subscriptionKeys, 'auth');
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function requiredArray(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf('Expected array value for "%s".', $key));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $values
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
     * @param  array<array-key, mixed>  $values
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
     * @param  array<array-key, mixed>  $values
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
     * @param  array<array-key, mixed>  $values
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

    /**
     * @param  array<array-key, mixed>  $subscription
     */
    private function subscriptionExpirationTimestamp(array $subscription): ?Carbon
    {
        $expirationTime = $subscription['expiration_time'] ?? null;

        if ($expirationTime === null) {
            return null;
        }

        if (! is_int($expirationTime)) {
            throw new InvalidArgumentException('Expected integer value for "expiration_time".');
        }

        return Carbon::createFromTimestampMsUTC($expirationTime);
    }

    private function subscriptionEndpointOrigin(string $endpoint): string
    {
        $components = parse_url($endpoint);

        if (! is_array($components)
            || ! is_string($components['scheme'] ?? null)
            || ! is_string($components['host'] ?? null)
        ) {
            throw new InvalidArgumentException('Expected a valid absolute URI for "endpoint".');
        }

        $origin = strtolower($components['scheme']).'://'.strtolower($components['host']);

        if (is_int($components['port'] ?? null)) {
            $origin .= ':'.$components['port'];
        }

        return $origin;
    }
}
