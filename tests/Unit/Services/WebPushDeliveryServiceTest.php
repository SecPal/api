<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Contracts\WebPushTransportInterface;
use App\Models\PushDeviceRegistration;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\WebPushDeliveryService;
use App\Support\WebPushTransportResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    config([
        'services.web_push.public_key' => 'BE9tfo-aCxwtPk9QYXKDlAUGBwgJCgsMDQ4PEBESExQVobLD1OX2BxgpMEFSY3SFlgcYKTBLXG1-j5ABAgMEBQY',
        'services.web_push.subject' => 'mailto:notifications@secpal.dev',
        'services.web_push.private_key' => '4AqAyV7R7cFAKE4tYEXAMPLEd91SOA45Qjmj1UzYQ0Wc',
        'services.web_push.ttl' => 900,
        'services.web_push.urgency' => 'high',
        'services.web_push.connect_timeout' => 5,
        'services.web_push.timeout' => 20,
    ]);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function createWebPushDeliveryRegistration(TenantKey $tenant, User $user, ?string $expiresAt = null): PushDeviceRegistration
{
    $expiresAt ??= now()->addDay()->toIso8601String();

    return PushDeviceRegistration::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => 'b1c2d3e4-f5a6-4789-8abc-1d2e3f4a5b6c',
        'platform' => 'browser',
        'provider' => 'web_push',
        'device_name' => 'Chrome workstation notifications',
        'push_token_plain' => 'https://fcm.googleapis.com/fcm/send/cVJmVnB1c2g6MTIzNDU2Nzg5MA:APA91bHabcdefghijklmno1234567890',
        'token_last_eight' => '8934b6d1',
        'last_lifecycle_event' => 'registered',
        'browser_name' => 'Chrome',
        'browser_version' => '136.0.7103.114',
        'service_worker_scope' => '/',
        'subscription_endpoint_origin' => 'https://fcm.googleapis.com',
        'subscription_p256dh_plain' => 'BAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE',
        'subscription_auth_plain' => 'AgICAgICAgICAgICAgICAg',
        'subscription_expires_at' => $expiresAt,
        'bootstrap_version' => 'v1',
        'schema_version' => 4,
        'push_metadata_revision' => 5,
    ]);
}

test('service sends customer-owned web push notifications against the stored browser subscription', function (): void {
    $registration = createWebPushDeliveryRegistration($this->tenant, $this->user);

    $transport = Mockery::mock(WebPushTransportInterface::class);
    $transport->shouldReceive('send')
        ->once()
        ->withArgs(function (array $subscription, string $payload, array $options): bool {
            $decodedPayload = json_decode($payload, true);

            return $subscription === [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/cVJmVnB1c2g6MTIzNDU2Nzg5MA:APA91bHabcdefghijklmno1234567890',
                'contentEncoding' => 'aes128gcm',
                'keys' => [
                    'p256dh' => 'BAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE',
                    'auth' => 'AgICAgICAgICAgICAgICAg',
                ],
            ]
                && $decodedPayload === [
                    'title' => 'Compliance alert',
                    'body' => 'Permit expires soon.',
                    'data' => [
                        'category' => 'compliance_alert',
                    ],
                ]
                && $options === [
                    'TTL' => 900,
                    'urgency' => 'high',
                    'contentType' => 'application/json',
                ];
        })
        ->andReturn(new WebPushTransportResult(
            successful: true,
            statusCode: 201,
            subscriptionExpired: false,
        ));

    app()->instance(WebPushTransportInterface::class, $transport);

    $result = app(WebPushDeliveryService::class)->send(
        $registration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect($result)->toBe([
        'delivered' => true,
        'stale_subscription' => false,
        'stale_reason' => null,
        'provider_status_code' => 201,
    ]);

    $this->assertDatabaseHas('push_device_registrations', [
        'id' => $registration->id,
    ]);
});

test('service preserves a zero web push ttl for online-only delivery', function (): void {
    $registration = createWebPushDeliveryRegistration($this->tenant, $this->user);

    config([
        'services.web_push.ttl' => 0,
    ]);

    $transport = Mockery::mock(WebPushTransportInterface::class);
    $transport->shouldReceive('send')
        ->once()
        ->withArgs(function (array $subscription, string $payload, array $options): bool {
            return ($subscription['contentEncoding'] ?? null) === 'aes128gcm'
                && $payload === '{"title":"Compliance alert","body":"Permit expires soon.","data":{"category":"compliance_alert"}}'
                && $options === [
                    'TTL' => 0,
                    'urgency' => 'high',
                    'contentType' => 'application/json',
                ];
        })
        ->andReturn(new WebPushTransportResult(
            successful: true,
            statusCode: 201,
            subscriptionExpired: false,
        ));

    app()->instance(WebPushTransportInterface::class, $transport);

    $result = app(WebPushDeliveryService::class)->send(
        $registration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect($result['provider_status_code'])->toBe(201)
        ->and($result['delivered'])->toBeTrue();
});

test('service deletes stale browser subscriptions when the push service reports expiration', function (): void {
    $registration = createWebPushDeliveryRegistration($this->tenant, $this->user);

    $transport = Mockery::mock(WebPushTransportInterface::class);
    $transport->shouldReceive('send')
        ->once()
        ->andReturn(new WebPushTransportResult(
            successful: false,
            statusCode: 410,
            subscriptionExpired: true,
        ));

    app()->instance(WebPushTransportInterface::class, $transport);

    $result = app(WebPushDeliveryService::class)->send(
        $registration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect($result)->toBe([
        'delivered' => false,
        'stale_subscription' => true,
        'stale_reason' => 'subscription_expired',
        'provider_status_code' => 410,
    ]);

    $this->assertDatabaseMissing('push_device_registrations', [
        'id' => $registration->id,
    ]);
});

test('service treats transient push-service failures as retryable and does not leak subscription material', function (): void {
    $registration = createWebPushDeliveryRegistration($this->tenant, $this->user);

    $transport = Mockery::mock(WebPushTransportInterface::class);
    $transport->shouldReceive('send')
        ->once()
        ->andReturn(new WebPushTransportResult(
            successful: false,
            statusCode: 503,
            subscriptionExpired: false,
        ));

    app()->instance(WebPushTransportInterface::class, $transport);

    $exception = null;

    try {
        app(WebPushDeliveryService::class)->send(
            $registration,
            'Compliance alert',
            'Permit expires soon.',
            ['category' => 'compliance_alert'],
        );
    } catch (RuntimeException $runtimeException) {
        $exception = $runtimeException;
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception?->getMessage())->toBe('Web push delivery failed with HTTP 503.')
        ->and($exception?->getMessage())->not->toContain('cVJmVnB1c2g6MTIzNDU2Nzg5MA')
        ->and($exception?->getMessage())->not->toContain('BAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE')
        ->and($exception?->getMessage())->not->toContain('AgICAgICAgICAgICAgICAg');

    $this->assertDatabaseHas('push_device_registrations', [
        'id' => $registration->id,
    ]);
});

test('service rejects delivery when the decrypted endpoint origin does not match the stored subscription origin', function (): void {
    $registration = createWebPushDeliveryRegistration($this->tenant, $this->user);

    $registration->getConnection()
        ->table('push_device_registrations')
        ->where('id', $registration->id)
        ->update(['subscription_endpoint_origin' => 'https://attacker.internal']);

    $freshRegistration = PushDeviceRegistration::query()->findOrFail($registration->id);

    $transport = Mockery::mock(WebPushTransportInterface::class);
    $transport->shouldNotReceive('send');

    app()->instance(WebPushTransportInterface::class, $transport);

    expect(fn () => app(WebPushDeliveryService::class)->send(
        $freshRegistration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    ))->toThrow(RuntimeException::class, 'Web push endpoint origin does not match the stored subscription origin.');

    $this->assertDatabaseHas('push_device_registrations', [
        'id' => $registration->id,
    ]);
});

test('service rejects delivery when the stored subscription origin is null', function (): void {
    $registration = createWebPushDeliveryRegistration($this->tenant, $this->user);

    $registration->getConnection()
        ->table('push_device_registrations')
        ->where('id', $registration->id)
        ->update(['subscription_endpoint_origin' => null]);

    $freshRegistration = PushDeviceRegistration::query()->findOrFail($registration->id);

    $transport = Mockery::mock(WebPushTransportInterface::class);
    $transport->shouldNotReceive('send');

    app()->instance(WebPushTransportInterface::class, $transport);

    expect(fn () => app(WebPushDeliveryService::class)->send(
        $freshRegistration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    ))->toThrow(RuntimeException::class, 'Web push endpoint origin does not match the stored subscription origin.');

    $this->assertDatabaseHas('push_device_registrations', [
        'id' => $registration->id,
    ]);
});

test('service deletes the registration when encrypted browser subscription material is corrupted', function (): void {
    $registration = createWebPushDeliveryRegistration($this->tenant, $this->user);

    $registration->getConnection()
        ->table('push_device_registrations')
        ->where('id', $registration->id)
        ->update(['subscription_auth_enc' => 'not-valid-json']);

    $freshRegistration = PushDeviceRegistration::query()->findOrFail($registration->id);

    $transport = Mockery::mock(WebPushTransportInterface::class);
    $transport->shouldNotReceive('send');

    app()->instance(WebPushTransportInterface::class, $transport);

    $result = app(WebPushDeliveryService::class)->send(
        $freshRegistration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect($result)->toBe([
        'delivered' => false,
        'stale_subscription' => true,
        'stale_reason' => 'decryption_failure',
        'provider_status_code' => null,
    ]);

    $this->assertDatabaseMissing('push_device_registrations', [
        'id' => $registration->id,
    ]);
});

test('service deletes malformed browser subscriptions before attempting delivery', function (): void {
    $registration = createWebPushDeliveryRegistration($this->tenant, $this->user);

    $registration->subscription_p256dh_plain = 'not_base64url***';
    $registration->save();

    $freshRegistration = PushDeviceRegistration::query()->findOrFail($registration->id);

    $transport = Mockery::mock(WebPushTransportInterface::class);
    $transport->shouldNotReceive('send');

    app()->instance(WebPushTransportInterface::class, $transport);

    $result = app(WebPushDeliveryService::class)->send(
        $freshRegistration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect($result)->toBe([
        'delivered' => false,
        'stale_subscription' => true,
        'stale_reason' => 'invalid_subscription',
        'provider_status_code' => null,
    ]);

    $this->assertDatabaseMissing('push_device_registrations', [
        'id' => $registration->id,
    ]);
});

test('service deletes browser subscriptions with short auth secrets before attempting delivery', function (): void {
    $registration = createWebPushDeliveryRegistration($this->tenant, $this->user);

    $registration->subscription_auth_plain = 'AgICAgICAgI';
    $registration->save();

    $freshRegistration = PushDeviceRegistration::query()->findOrFail($registration->id);

    $transport = Mockery::mock(WebPushTransportInterface::class);
    $transport->shouldNotReceive('send');

    app()->instance(WebPushTransportInterface::class, $transport);

    $result = app(WebPushDeliveryService::class)->send(
        $freshRegistration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect($result)->toBe([
        'delivered' => false,
        'stale_subscription' => true,
        'stale_reason' => 'invalid_subscription',
        'provider_status_code' => null,
    ]);

    $this->assertDatabaseMissing('push_device_registrations', [
        'id' => $registration->id,
    ]);
});

test('service deletes browser subscriptions when transport fails before provider response due to an invalid p256dh key', function (): void {
    $registration = createWebPushDeliveryRegistration($this->tenant, $this->user);

    $transport = Mockery::mock(WebPushTransportInterface::class);
    $transport->shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException(
            'Web push delivery request failed before the push service responded.',
            previous: new RuntimeException('Unable to compute the agreement key.'),
        ));

    app()->instance(WebPushTransportInterface::class, $transport);

    $result = app(WebPushDeliveryService::class)->send(
        $registration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect($result)->toBe([
        'delivered' => false,
        'stale_subscription' => true,
        'stale_reason' => 'invalid_subscription',
        'provider_status_code' => null,
    ]);

    $this->assertDatabaseMissing('push_device_registrations', [
        'id' => $registration->id,
    ]);
});
