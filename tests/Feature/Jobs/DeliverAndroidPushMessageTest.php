<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Jobs\DeliverAndroidPushMessage;
use App\Models\PushDeviceRegistration;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\AndroidPushDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    config([
        'services.fcm.project_id' => 'customer-owned-project',
        'services.fcm.client_email' => 'firebase-adminsdk@customer-owned-project.iam.gserviceaccount.com',
        'services.fcm.private_key' => generateQueuedFirebaseTestPrivateKey(),
        'services.fcm.token_uri' => 'https://oauth2.googleapis.com/token',
        'services.fcm.connect_timeout' => 5,
        'services.fcm.timeout' => 10,
    ]);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function createQueuedAndroidPushRegistration(TenantKey $tenant, User $user): PushDeviceRegistration
{
    return PushDeviceRegistration::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b',
        'platform' => 'android',
        'provider' => 'fcm',
        'device_name' => 'SM-G556B reception tablet',
        'push_token_plain' => 'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef',
        'last_lifecycle_event' => 'registered',
        'package_name' => 'app.secpal',
        'package_version_name' => '1.5.0',
        'package_version_code' => 10500,
        'manufacturer' => 'Samsung',
        'model' => 'SM-G556B',
        'android_version' => '16',
        'sdk_int' => 36,
        'bootstrap_version' => 'v1',
        'schema_version' => 2,
        'push_metadata_revision' => 3,
    ]);
}

function generateQueuedFirebaseTestPrivateKey(): string
{
    $privateKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($privateKey === false) {
        throw new RuntimeException('Unable to generate a test RSA private key for queued Android push delivery coverage.');
    }

    $exportedKey = null;
    $exportSucceeded = openssl_pkey_export($privateKey, $exportedKey);

    if (! $exportSucceeded || ! is_string($exportedKey) || trim($exportedKey) === '') {
        throw new RuntimeException('Unable to export the generated queued Android push delivery private key.');
    }

    return $exportedKey;
}

test('job dispatches android push delivery for an existing registration', function (): void {
    $registration = createQueuedAndroidPushRegistration($this->tenant, $this->user);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'google-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
        'https://fcm.googleapis.com/v1/projects/customer-owned-project/messages:send' => Http::response([
            'name' => 'projects/customer-owned-project/messages/0:1710000000000000%1234abcd1234abcd',
        ], 200),
    ]);

    (new DeliverAndroidPushMessage(
        $registration->id,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    ))->handle(app(AndroidPushDeliveryService::class));

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/customer-owned-project/messages:send') {
            return false;
        }

        return data_get($request->data(), 'message.notification.title') === 'Compliance alert'
            && data_get($request->data(), 'message.notification.body') === 'Permit expires soon.'
            && data_get($request->data(), 'message.data.category') === 'compliance_alert';
    });
});

test('job skips missing registrations', function (): void {
    Http::fake();

    (new DeliverAndroidPushMessage(
        (string) Str::uuid(),
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    ))->handle(app(AndroidPushDeliveryService::class));

    Http::assertNothingSent();
});

test('job can be queued for asynchronous android push delivery', function (): void {
    Queue::fake();

    DeliverAndroidPushMessage::dispatch(
        'push-registration-id',
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    Queue::assertPushed(DeliverAndroidPushMessage::class, function (DeliverAndroidPushMessage $job): bool {
        return $job->pushDeviceRegistrationId === 'push-registration-id'
            && $job->title === 'Compliance alert'
            && $job->body === 'Permit expires soon.'
            && $job->data === ['category' => 'compliance_alert'];
    });

    expect((new DeliverAndroidPushMessage('push-registration-id', 'title', 'body'))->backoff())
        ->toBe([10, 60, 300])
        ->and((new DeliverAndroidPushMessage('push-registration-id', 'title', 'body'))->tries)->toBe(3)
        ->and((new DeliverAndroidPushMessage('push-registration-id', 'title', 'body'))->timeout)->toBe(30);
});
