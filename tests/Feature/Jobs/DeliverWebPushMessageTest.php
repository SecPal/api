<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Contracts\WebPushDeliveryServiceInterface;
use App\Jobs\DeliverWebPushMessage;
use App\Models\PushDeviceRegistration;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\QueueWebPushDeliveryService;
use App\Support\BootstrapContract;
use App\Support\WebPushDeliveryConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->otherUser = User::factory()->create(['tenant_id' => $this->otherTenant->id]);

    config([
        'services.web_push.public_key' => 'BE9tfo-aCxwtPk9QYXKDlAUGBwgJCgsMDQ4PEBESExQVobLD1OX2BxgpMEFSY3SFlgcYKTBLXG1-j5ABAgMEBQY',
        'services.web_push.subject' => 'mailto:notifications@secpal.dev',
        'services.web_push.private_key' => '4AqAyV7R7cFAKE4tYEXAMPLEd91SOA45Qjmj1UzYQ0Wc',
    ]);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function createQueuedWebPushRegistration(TenantKey $tenant, User $user, string $installationId = 'b1c2d3e4-f5a6-4789-8abc-1d2e3f4a5b6c'): PushDeviceRegistration
{
    return PushDeviceRegistration::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
        'platform' => BootstrapContract::CLIENT_PLATFORM_BROWSER,
        'provider' => BootstrapContract::WEB_PUSH_PROVIDER,
        'device_name' => 'Chrome workstation notifications',
        'push_token_plain' => 'https://fcm.googleapis.com/fcm/send/'.Str::lower(str_replace('-', '', $installationId)),
        'token_last_eight' => substr(hash('sha256', $installationId), 0, 8),
        'last_lifecycle_event' => 'registered',
        'browser_name' => 'Chrome',
        'browser_version' => '136.0.7103.114',
        'service_worker_scope' => '/',
        'subscription_endpoint_origin' => 'https://fcm.googleapis.com',
        'subscription_p256dh_plain' => 'BAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE',
        'subscription_auth_plain' => 'AgICAgICAgICAgICAgICAg',
        'subscription_expires_at' => '2026-06-26T12:00:00Z',
        'bootstrap_version' => 'v1',
        'schema_version' => 4,
        'push_metadata_revision' => 5,
    ]);
}

function createQueuedAndroidRegistration(TenantKey $tenant, User $user, string $installationId = 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b'): PushDeviceRegistration
{
    return PushDeviceRegistration::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
        'platform' => BootstrapContract::CLIENT_PLATFORM_ANDROID,
        'provider' => BootstrapContract::ANDROID_PUSH_PROVIDER,
        'device_name' => 'Reception tablet',
        'push_token_plain' => 'android-token-'.$installationId,
        'token_last_eight' => substr(hash('sha256', $installationId), 0, 8),
        'last_lifecycle_event' => 'registered',
        'package_name' => 'app.secpal',
        'package_version_name' => '1.5.0',
        'package_version_code' => 10500,
        'manufacturer' => 'Samsung',
        'model' => 'SM-G556B',
        'android_version' => '16',
        'sdk_int' => 36,
        'bootstrap_version' => 'v1',
        'schema_version' => 4,
        'push_metadata_revision' => 3,
    ]);
}

test('job dispatches web push delivery for an existing registration', function (): void {
    $registration = createQueuedWebPushRegistration($this->tenant, $this->user);

    $deliveryService = Mockery::mock(WebPushDeliveryServiceInterface::class);
    $deliveryService->shouldReceive('send')
        ->once()
        ->withArgs(function (PushDeviceRegistration $queuedRegistration, string $title, string $body, array $data) use ($registration): bool {
            return $queuedRegistration->is($registration)
                && $title === 'Compliance alert'
                && $body === 'Permit expires soon.'
                && $data === ['category' => 'compliance_alert'];
        })
        ->andReturn([
            'delivered' => true,
            'stale_subscription' => false,
            'stale_reason' => null,
            'provider_status_code' => 201,
        ]);

    (new DeliverWebPushMessage(
        $registration->id,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    ))->handle($deliveryService, app(WebPushDeliveryConfiguration::class));
});

test('job skips missing registrations', function (): void {
    $deliveryService = Mockery::mock(WebPushDeliveryServiceInterface::class);
    $deliveryService->shouldNotReceive('send');

    $job = (new DeliverWebPushMessage(
        (string) Str::uuid(),
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    ))->withFakeQueueInteractions();

    $job->handle($deliveryService, app(WebPushDeliveryConfiguration::class));

    $job->assertNotFailed();
});

test('job makes no delivery attempts when web push vapid credentials are missing', function (): void {
    $registration = createQueuedWebPushRegistration($this->tenant, $this->user);

    config([
        'services.web_push.subject' => null,
        'services.web_push.private_key' => null,
    ]);

    $deliveryService = Mockery::mock(WebPushDeliveryServiceInterface::class);
    $deliveryService->shouldNotReceive('send');

    $job = (new DeliverWebPushMessage(
        $registration->id,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    ))->withFakeQueueInteractions();

    $job->handle($deliveryService, app(WebPushDeliveryConfiguration::class));

    $job->assertFailedWith('Web push delivery is not configured for this deployment.');
});

test('job allows queue retries when delivery service throws a RuntimeException', function (): void {
    $registration = createQueuedWebPushRegistration($this->tenant, $this->user);

    $deliveryService = Mockery::mock(WebPushDeliveryServiceInterface::class);
    $deliveryService->shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException('Web push endpoint origin does not match the stored subscription origin.'));

    $job = new DeliverWebPushMessage(
        $registration->id,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect(fn () => $job->handle($deliveryService, app(WebPushDeliveryConfiguration::class)))
        ->toThrow(RuntimeException::class, 'Web push endpoint origin does not match the stored subscription origin.');
});

test('queue dispatcher scopes queued web push delivery to the tenant and deduplicates the targeted subscription set', function (): void {
    Queue::fake();

    $matchingRegistration = createQueuedWebPushRegistration($this->tenant, $this->user);
    $foreignTenantRegistration = createQueuedWebPushRegistration($this->otherTenant, $this->otherUser, 'd4e5f6a7-b8c9-4012-9abc-4d5e6f7a8b9c');
    $androidRegistration = createQueuedAndroidRegistration($this->tenant, $this->user);

    $queuedRegistrationIds = app(QueueWebPushDeliveryService::class)->dispatchToRegistrations(
        $this->tenant->id,
        [
            $matchingRegistration->id,
            $matchingRegistration->id,
            $foreignTenantRegistration->id,
            $androidRegistration->id,
        ],
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect($queuedRegistrationIds)->toBe([$matchingRegistration->id]);

    Queue::assertPushed(DeliverWebPushMessage::class, 1);
    Queue::assertPushed(DeliverWebPushMessage::class, function (DeliverWebPushMessage $job) use ($matchingRegistration): bool {
        return $job->pushDeviceRegistrationId === $matchingRegistration->id
            && $job->title === 'Compliance alert'
            && $job->body === 'Permit expires soon.'
            && $job->data === ['category' => 'compliance_alert'];
    });
});
