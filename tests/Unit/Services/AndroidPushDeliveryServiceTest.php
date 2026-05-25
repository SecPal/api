<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\PushDeviceRegistration;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\AndroidPushDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TenantKeyReadabilityOverride;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $privateKey = generateFirebaseTestPrivateKey();

    config([
        'services.fcm.project_id' => 'customer-owned-project',
        'services.fcm.client_email' => 'firebase-adminsdk@customer-owned-project.iam.gserviceaccount.com',
        'services.fcm.private_key' => $privateKey,
        'services.fcm.token_uri' => 'https://oauth2.googleapis.com/token',
        'services.fcm.connect_timeout' => 5,
        'services.fcm.timeout' => 10,
    ]);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
    TenantKeyReadabilityOverride::clear();
});

function createAndroidPushRegistration(TenantKey $tenant, User $user): PushDeviceRegistration
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

function generateFirebaseTestPrivateKey(): string
{
    $privateKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($privateKey === false) {
        throw new RuntimeException('Unable to generate a test RSA private key for Android push delivery coverage.');
    }

    $exportedKey = null;
    $exportSucceeded = openssl_pkey_export($privateKey, $exportedKey);

    if (! $exportSucceeded || ! is_string($exportedKey) || trim($exportedKey) === '') {
        throw new RuntimeException('Unable to export the generated Android push delivery private key.');
    }

    return $exportedKey;
}

test('service sends android push through customer owned firebase credentials', function (): void {
    $registration = createAndroidPushRegistration($this->tenant, $this->user);

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

    $result = app(AndroidPushDeliveryService::class)->send(
        $registration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect($result)->toBe([
        'delivered' => true,
        'provider_message_id' => 'projects/customer-owned-project/messages/0:1710000000000000%1234abcd1234abcd',
        'invalid_token' => false,
        'provider_error_code' => null,
    ]);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }

        parse_str($request->body(), $body);

        return ($body['grant_type'] ?? null) === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && is_string($body['assertion'] ?? null)
            && $body['assertion'] !== '';
    });

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/customer-owned-project/messages:send') {
            return false;
        }

        $payload = $request->data();

        return $request->hasHeader('Authorization', 'Bearer google-access-token')
            && data_get($payload, 'message.token') === 'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
            && data_get($payload, 'message.notification.title') === 'Compliance alert'
            && data_get($payload, 'message.notification.body') === 'Permit expires soon.'
            && data_get($payload, 'message.data.category') === 'compliance_alert';
    });

    $this->assertDatabaseHas('push_device_registrations', [
        'id' => $registration->id,
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
});

test('service omits empty message data from firebase payload', function (): void {
    $registration = createAndroidPushRegistration($this->tenant, $this->user);

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

    $result = app(AndroidPushDeliveryService::class)->send(
        $registration,
        'Compliance alert',
        'Permit expires soon.',
    );

    expect($result)->toBe([
        'delivered' => true,
        'provider_message_id' => 'projects/customer-owned-project/messages/0:1710000000000000%1234abcd1234abcd',
        'invalid_token' => false,
        'provider_error_code' => null,
    ]);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/customer-owned-project/messages:send') {
            return false;
        }

        $payload = $request->data();
        $message = $payload['message'] ?? null;

        return is_array($message)
            && ! array_key_exists('data', $message);
    });
});

test('service fails closed when customer owned firebase credentials are incomplete', function (): void {
    $registration = createAndroidPushRegistration($this->tenant, $this->user);

    config(['services.fcm.private_key' => null]);

    Http::fake();

    expect(fn () => app(AndroidPushDeliveryService::class)->send(
        $registration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    ))->toThrow(RuntimeException::class, 'Android push delivery is not configured for this deployment.');

    Http::assertNothingSent();
});

test('service deletes registration and returns invalid token when device token decryption fails', function (): void {
    $registration = createAndroidPushRegistration($this->tenant, $this->user);

    // Corrupt the stored ciphertext so the EncryptedWithDek cast throws on get.
    $registration->getConnection()
        ->table('push_device_registrations')
        ->where('id', $registration->id)
        ->update(['push_token_enc' => 'not-valid-json']);

    $freshRegistration = PushDeviceRegistration::query()->findOrFail($registration->id);

    Http::fake();

    $result = app(AndroidPushDeliveryService::class)->send(
        $freshRegistration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect($result)->toBe([
        'delivered' => false,
        'provider_message_id' => null,
        'invalid_token' => true,
        'provider_error_code' => 'DECRYPTION_FAILURE',
    ]);

    $this->assertDatabaseMissing('push_device_registrations', [
        'id' => $registration->id,
    ]);

    Http::assertNothingSent();
});

test('service surfaces tenant key runtime failures without deleting registration', function (): void {
    $registration = createAndroidPushRegistration($this->tenant, $this->user);

    TenantKeyReadabilityOverride::markUnreadable(getTestKekPath());

    Http::fake();

    expect(fn () => app(AndroidPushDeliveryService::class)->send(
        $registration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    ))->toThrow(RuntimeException::class, 'KEK file is not readable by this process');

    $this->assertDatabaseHas('push_device_registrations', [
        'id' => $registration->id,
    ]);

    Http::assertNothingSent();
});

test('service fails closed when device token is absent on the loaded model without deleting registration', function (): void {
    $registration = createAndroidPushRegistration($this->tenant, $this->user);

    // Force the in-memory cast to return null by wiping the raw attribute value.
    // The column is NOT NULL in the DB, so we simulate a partially-hydrated model
    // where the attribute is unset after hydration (e.g. via setRawAttributes).
    $registration->setRawAttributes(array_merge(
        $registration->getRawOriginal(),
        ['push_token_enc' => null],
    ));

    Http::fake();

    expect(fn () => app(AndroidPushDeliveryService::class)->send(
        $registration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    ))->toThrow(RuntimeException::class, 'Android push delivery requires a decryptable device token.');

    $this->assertDatabaseHas('push_device_registrations', [
        'id' => $registration->id,
    ]);

    Http::assertNothingSent();
});

test('service deletes stale registrations when firebase reports an unregistered token', function (): void {
    $registration = createAndroidPushRegistration($this->tenant, $this->user);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'google-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
        'https://fcm.googleapis.com/v1/projects/customer-owned-project/messages:send' => Http::response([
            'error' => [
                'code' => 404,
                'message' => 'Requested entity was not found.',
                'status' => 'NOT_FOUND',
                'details' => [
                    [
                        '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'UNREGISTERED',
                    ],
                ],
            ],
        ], 404),
    ]);

    $result = app(AndroidPushDeliveryService::class)->send(
        $registration,
        'Compliance alert',
        'Permit expires soon.',
        ['category' => 'compliance_alert'],
    );

    expect($result)->toBe([
        'delivered' => false,
        'provider_message_id' => null,
        'invalid_token' => true,
        'provider_error_code' => 'UNREGISTERED',
    ]);

    $this->assertDatabaseMissing('push_device_registrations', [
        'id' => $registration->id,
    ]);
});
