<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    config([
        'bootstrap.features.notification_channels.android_fcm' => true,
        'bootstrap.features.notification_channels.web_push' => false,
        'bootstrap.notification_channels.android_fcm.metadata_revision' => 3,
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.api_key' => 'public-client-api-key-demo-1234567890',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.project_id' => 'secpal-demo-push',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.application_id' => '1:1234567890:android:abcdef1234567890',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.sender_id' => '1234567890',
    ]);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * @return array{tenant: TenantKey, user: User}
 */
function createPushDeviceContext(): array
{
    $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    return [
        'tenant' => $tenant,
        'user' => $user,
    ];
}

/**
 * @return array{tenant: TenantKey, user: User}
 */
function createBrowserPushContext(): array
{
    $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'browser-web-push-'.Str::uuid().'@testing.secpal.dev',
        'password' => Hash::make('password123'),
    ]);

    return [
        'tenant' => $tenant,
        'user' => $user,
    ];
}

function enableWebPushNotificationChannel(): void
{
    config([
        'bootstrap.features.notification_channels.android_fcm' => false,
        'bootstrap.features.notification_channels.web_push' => true,
        'bootstrap.notification_channels.web_push.metadata_revision' => 5,
        'bootstrap.notification_channels.web_push.public_runtime_metadata.vapid_public_key' => 'BE9tfo-aCxwtPk9QYXKDlAUGBwgJCgsMDQ4PEBESExQVobLD1OX2BxgpMEFSY3SFlgcYKTBLXG1-j5ABAgMEBQY',
    ]);
}

function loginBrowserSession(Tests\TestCase $testCase, User $user): void
{
    $testCase->withHeaders(spaCsrfHeaders($testCase))->postJson('/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertOk();
}

function androidNotificationInstallationPayload(string $pushToken, string $lifecycleEvent = 'registered'): array
{
    return [
        'channel' => 'android_fcm',
        'installation_name' => 'SM-G556B reception tablet',
        'lifecycle_event' => $lifecycleEvent,
        'runtime' => [
            'bootstrap_version' => 'v1',
            'schema_version' => 4,
            'metadata_revision' => 3,
        ],
        'registration' => [
            'push_token' => $pushToken,
            'app' => [
                'package_name' => 'app.secpal',
                'package_version_name' => '1.5.0',
                'package_version_code' => 10500,
            ],
            'device' => [
                'manufacturer' => 'Samsung',
                'model' => 'SM-G556B',
                'android_version' => '16',
                'sdk_int' => 36,
            ],
        ],
    ];
}

function webPushPayload(string $endpoint, string $lifecycleEvent = 'registered'): array
{
    return [
        'channel' => 'web_push',
        'installation_name' => 'Chrome workstation notifications',
        'lifecycle_event' => $lifecycleEvent,
        'runtime' => [
            'bootstrap_version' => 'v1',
            'schema_version' => 4,
            'metadata_revision' => 5,
        ],
        'registration' => [
            'browser' => [
                'browser_name' => 'Chrome',
                'browser_version' => '136.0.7103.114',
                'service_worker_scope' => '/',
            ],
            'subscription' => [
                'endpoint' => $endpoint,
                'expiration_time' => 1782475200000,
                'keys' => [
                    'p256dh' => 'BElx7P1qA2rS9tUvWxYz0123456789abcdefghijklmnopqrstuv',
                    'auth' => 'K7d9Lm2PqRs',
                ],
            ],
        ],
    ];
}

test('authenticated user can create android notification installation', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    Carbon::setTestNow(Carbon::parse('2026-05-30 12:34:56 UTC'));

    $installationId = 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b';

    $response = putJson('/v1/me/notification-installations/'.$installationId, androidNotificationInstallationPayload(
        'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
    ));

    $response->assertCreated()
        ->assertJsonPath('data.installation_id', $installationId)
        ->assertJsonPath('data.channel', 'android_fcm')
        ->assertJsonPath('data.installation_name', 'SM-G556B reception tablet')
        ->assertJsonPath('data.credential_reference', '90abcdef')
        ->assertJsonPath('data.last_lifecycle_event', 'registered')
        ->assertJsonPath('data.registration.app.package_name', 'app.secpal')
        ->assertJsonPath('data.registration.app.package_version_name', '1.5.0')
        ->assertJsonPath('data.registration.app.package_version_code', 10500)
        ->assertJsonPath('data.registration.device.manufacturer', 'Samsung')
        ->assertJsonPath('data.registration.device.model', 'SM-G556B')
        ->assertJsonPath('data.registration.device.android_version', '16')
        ->assertJsonPath('data.registration.device.sdk_int', 36)
        ->assertJsonPath('data.runtime.bootstrap_version', 'v1')
        ->assertJsonPath('data.runtime.schema_version', 4)
        ->assertJsonPath('data.runtime.metadata_revision', 3)
        ->assertJsonPath('data.created_at', '2026-05-30T12:34:56Z')
        ->assertJsonPath('data.updated_at', '2026-05-30T12:34:56Z');

    $this->assertDatabaseHas('push_device_registrations', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
        'platform' => 'android',
        'provider' => 'fcm',
        'device_name' => 'SM-G556B reception tablet',
        'token_last_eight' => '90abcdef',
        'last_lifecycle_event' => 'registered',
        'package_name' => 'app.secpal',
        'package_version_name' => '1.5.0',
        'package_version_code' => 10500,
        'bootstrap_version' => 'v1',
        'schema_version' => 4,
        'push_metadata_revision' => 3,
    ]);

    $storedToken = DB::table('push_device_registrations')
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->where('installation_id', $installationId)
        ->value('push_token_enc');

    expect($storedToken)->toBeString()->not->toBe(
        'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
    );

    $decodedToken = json_decode($storedToken, true);

    expect($decodedToken)->toBeArray()
        ->toHaveKeys(['ciphertext', 'nonce'])
        ->and($decodedToken['ciphertext'])->toBeString()->not->toBe('')
        ->and($decodedToken['nonce'])->toBeString()->not->toBe('');

    Carbon::setTestNow();
});

test('session-authenticated browser user can create a web push notification installation on the canonical surface', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createBrowserPushContext();

    enableWebPushNotificationChannel();
    loginBrowserSession($this, $user);

    $installationId = 'b1c2d3e4-f5a6-4789-8abc-1d2e3f4a5b6c';
    $endpoint = 'https://fcm.googleapis.com/fcm/send/cVJmVnB1c2g6MTIzNDU2Nzg5MA:APA91bHabcdefghijklmno1234567890';
    $credentialReference = substr(hash('sha256', $endpoint), 0, 8);

    $response = $this->withHeaders(spaCsrfHeaders($this))
        ->putJson('/v1/me/notification-installations/'.$installationId, webPushPayload($endpoint));

    $response->assertCreated()
        ->assertJsonPath('data.installation_id', $installationId)
        ->assertJsonPath('data.channel', 'web_push')
        ->assertJsonPath('data.installation_name', 'Chrome workstation notifications')
        ->assertJsonPath('data.credential_reference', $credentialReference)
        ->assertJsonPath('data.last_lifecycle_event', 'registered')
        ->assertJsonPath('data.registration.browser.browser_name', 'Chrome')
        ->assertJsonPath('data.registration.browser.browser_version', '136.0.7103.114')
        ->assertJsonPath('data.registration.browser.service_worker_scope', '/')
        ->assertJsonPath('data.registration.subscription_endpoint_origin', 'https://fcm.googleapis.com')
        ->assertJsonPath('data.registration.subscription_expires_at', '2026-06-26T12:00:00Z')
        ->assertJsonPath('data.runtime.bootstrap_version', 'v1')
        ->assertJsonPath('data.runtime.schema_version', 4)
        ->assertJsonPath('data.runtime.metadata_revision', 5);

    expect($response->json('data.credential_reference'))->toBeString()->toHaveLength(8);
    expect($response->getContent())
        ->not->toContain($endpoint)
        ->not->toContain('BElx7P1qA2rS9tUvWxYz0123456789abcdefghijklmnopqrstuv')
        ->not->toContain('K7d9Lm2PqRs');

    $this->assertDatabaseHas('push_device_registrations', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
        'platform' => 'browser',
        'provider' => 'web_push',
        'device_name' => 'Chrome workstation notifications',
        'token_last_eight' => $credentialReference,
        'browser_name' => 'Chrome',
        'browser_version' => '136.0.7103.114',
        'service_worker_scope' => '/',
        'subscription_endpoint_origin' => 'https://fcm.googleapis.com',
        'bootstrap_version' => 'v1',
        'schema_version' => 4,
        'push_metadata_revision' => 5,
    ]);

    $storedCredentials = DB::table('push_device_registrations')
        ->select(['push_token_enc', 'subscription_p256dh_enc', 'subscription_auth_enc', 'subscription_expires_at'])
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->where('installation_id', $installationId)
        ->first();

    expect($storedCredentials)->not->toBeNull();

    foreach (['push_token_enc', 'subscription_p256dh_enc', 'subscription_auth_enc'] as $column) {
        $storedValue = $storedCredentials->{$column};

        expect($storedValue)->toBeString();

        $decodedValue = json_decode($storedValue, true);

        expect($decodedValue)->toBeArray()
            ->toHaveKeys(['ciphertext', 'nonce'])
            ->and($decodedValue['ciphertext'])->toBeString()->not->toBe('')
            ->and($decodedValue['nonce'])->toBeString()->not->toBe('');
    }

    expect($storedCredentials->push_token_enc)->not->toContain($endpoint);
    expect((string) $storedCredentials->subscription_expires_at)->toStartWith('2026-06-26 12:00:00');
});

test('session-authenticated browser user can rotate an existing web push notification installation', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createBrowserPushContext();

    enableWebPushNotificationChannel();
    loginBrowserSession($this, $user);

    $installationId = 'b1c2d3e4-f5a6-4789-8abc-1d2e3f4a5b6c';
    $createdEndpoint = 'https://fcm.googleapis.com/fcm/send/cVJmVnB1c2g6MTIzNDU2Nzg5MA:APA91bHabcdefghijklmno1234567890';
    $updatedEndpoint = 'https://updates.push.services.mozilla.com/wpush/v2/gAAAAABoQnRhdGVkLWtleS0xMjM0NTY3ODkw';
    $createdCredentialReference = substr(hash('sha256', $createdEndpoint), 0, 8);
    $updatedCredentialReference = substr(hash('sha256', $updatedEndpoint), 0, 8);

    $createdResponse = $this->withHeaders(spaCsrfHeaders($this))
        ->putJson('/v1/me/notification-installations/'.$installationId, webPushPayload($createdEndpoint));

    $createdResponse->assertCreated();

    $updatedResponse = $this->withHeaders(spaCsrfHeaders($this))
        ->putJson('/v1/me/notification-installations/'.$installationId, [
            ...webPushPayload($updatedEndpoint),
            'lifecycle_event' => 'credential_rotated',
            'registration' => [
                'browser' => [
                    'browser_name' => 'Firefox',
                    'browser_version' => '138.0',
                    'service_worker_scope' => '/',
                ],
                'subscription' => [
                    'endpoint' => $updatedEndpoint,
                    'expiration_time' => 1782561600000,
                    'keys' => [
                        'p256dh' => 'BMozillaRotatedP256dh0123456789abcdefghijklmnopqrstu',
                        'auth' => 'Lm9PqRs7TuV',
                    ],
                ],
            ],
        ]);

    $updatedResponse->assertOk()
        ->assertJsonPath('data.installation_id', $installationId)
        ->assertJsonPath('data.channel', 'web_push')
        ->assertJsonPath('data.installation_name', 'Chrome workstation notifications')
        ->assertJsonPath('data.credential_reference', $updatedCredentialReference)
        ->assertJsonPath('data.last_lifecycle_event', 'credential_rotated')
        ->assertJsonPath('data.registration.browser.browser_name', 'Firefox')
        ->assertJsonPath('data.registration.browser.browser_version', '138.0')
        ->assertJsonPath('data.registration.subscription_endpoint_origin', 'https://updates.push.services.mozilla.com')
        ->assertJsonPath('data.registration.subscription_expires_at', '2026-06-27T12:00:00Z')
        ->assertJsonPath('data.runtime.metadata_revision', 5);

    expect($createdResponse->json('data.credential_reference'))->toBe($createdCredentialReference);
    expect($updatedResponse->json('data.credential_reference'))->not->toBe($createdCredentialReference);

    expect(DB::table('push_device_registrations')
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->where('installation_id', $installationId)
        ->count())->toBe(1);
});

test('android notification installation accepts integer-like numeric strings', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    $installationId = 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b';

    putJson('/v1/me/notification-installations/'.$installationId, [
        ...androidNotificationInstallationPayload('e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'),
        'registration' => [
            'push_token' => 'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef',
            'app' => [
                'package_name' => 'app.secpal',
                'package_version_name' => '1.5.0',
                'package_version_code' => '10500',
            ],
            'device' => [
                'manufacturer' => 'Samsung',
                'model' => 'SM-G556B',
                'android_version' => '16',
                'sdk_int' => '36',
            ],
        ],
        'runtime' => [
            'bootstrap_version' => 'v1',
            'schema_version' => '4',
            'metadata_revision' => '3',
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.registration.app.package_version_code', 10500)
        ->assertJsonPath('data.registration.device.sdk_int', 36)
        ->assertJsonPath('data.runtime.schema_version', 4)
        ->assertJsonPath('data.runtime.metadata_revision', 3);

    $this->assertDatabaseHas('push_device_registrations', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
        'package_version_code' => 10500,
        'sdk_int' => 36,
        'schema_version' => 4,
        'push_metadata_revision' => 3,
    ]);
});

test('repeating put updates the existing android notification installation', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    $installationId = 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b';

    putJson('/v1/me/notification-installations/'.$installationId, androidNotificationInstallationPayload(
        'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
    ))->assertCreated();

    $response = putJson('/v1/me/notification-installations/'.$installationId, [
        ...androidNotificationInstallationPayload(
            'x9y8z7w6v5u4:APA91bHfedcba0987654321QwErTyUiOpAsDfGhJkLmNoPqRsTuVwXyZfedcba09',
            'credential_rotated',
        ),
        'registration' => [
            'push_token' => 'x9y8z7w6v5u4:APA91bHfedcba0987654321QwErTyUiOpAsDfGhJkLmNoPqRsTuVwXyZfedcba09',
            'app' => [
                'package_name' => 'app.secpal',
                'package_version_name' => '1.5.1',
                'package_version_code' => 10501,
            ],
            'device' => [
                'manufacturer' => 'Samsung',
                'model' => 'SM-G556B',
                'android_version' => '16',
                'sdk_int' => 36,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.installation_id', $installationId)
        ->assertJsonPath('data.credential_reference', 'fedcba09')
        ->assertJsonPath('data.last_lifecycle_event', 'credential_rotated')
        ->assertJsonPath('data.registration.app.package_version_name', '1.5.1')
        ->assertJsonPath('data.registration.app.package_version_code', 10501);

    $this->assertDatabaseHas('push_device_registrations', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
        'token_last_eight' => 'fedcba09',
        'last_lifecycle_event' => 'credential_rotated',
        'package_version_name' => '1.5.1',
        'package_version_code' => 10501,
    ]);

    expect(DB::table('push_device_registrations')
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->where('installation_id', $installationId)
        ->count())->toBe(1);
});

test('repeating put clears stale web push credentials when switching an installation to android', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createPushDeviceContext();

    enableWebPushNotificationChannel();
    actingAs($user, 'sanctum');

    $installationId = 'c2d3e4f5-a6b7-489a-8bcd-2e3f4a5b6c7d';

    putJson('/v1/me/notification-installations/'.$installationId, webPushPayload(
        'https://fcm.googleapis.com/fcm/send/cVJmVnB1c2g6MTIzNDU2Nzg5MA:APA91bHabcdefghijklmno1234567890'
    ))->assertCreated();

    config([
        'bootstrap.features.notification_channels.android_fcm' => true,
        'bootstrap.features.notification_channels.web_push' => false,
    ]);

    putJson('/v1/me/notification-installations/'.$installationId, androidNotificationInstallationPayload(
        'x9y8z7w6v5u4:APA91bHfedcba0987654321QwErTyUiOpAsDfGhJkLmNoPqRsTuVwXyZfedcba09',
        'credential_rotated',
    ))->assertOk()
        ->assertJsonPath('data.channel', 'android_fcm')
        ->assertJsonPath('data.credential_reference', 'fedcba09');

    $this->assertDatabaseHas('push_device_registrations', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
        'platform' => 'android',
        'provider' => 'fcm',
        'token_last_eight' => 'fedcba09',
    ]);

    $storedRegistration = DB::table('push_device_registrations')
        ->select(['subscription_p256dh_enc', 'subscription_auth_enc'])
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->where('installation_id', $installationId)
        ->first();

    expect($storedRegistration)->not->toBeNull()
        ->and($storedRegistration->subscription_p256dh_enc)->toBeNull()
        ->and($storedRegistration->subscription_auth_enc)->toBeNull();
});

test('same installation id can be registered independently in another tenant', function (): void {
    ['tenant' => $firstTenant, 'user' => $firstUser] = createPushDeviceContext();
    ['tenant' => $secondTenant, 'user' => $secondUser] = createPushDeviceContext();

    $installationId = 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b';

    actingAs($firstUser, 'sanctum');
    putJson('/v1/me/notification-installations/'.$installationId, androidNotificationInstallationPayload(
        'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
    ))->assertCreated();

    actingAs($secondUser, 'sanctum');
    putJson('/v1/me/notification-installations/'.$installationId, androidNotificationInstallationPayload(
        'n1m2b3v4c5x6:APA91bH0a1b2c3d4e5f6g7h8i9j0kLmNoPqRsTuVwXyZ76543210ghijkl90mnopqr'
    ))->assertCreated();

    $this->assertDatabaseHas('push_device_registrations', [
        'tenant_id' => $firstTenant->id,
        'user_id' => $firstUser->id,
        'installation_id' => $installationId,
    ]);

    $this->assertDatabaseHas('push_device_registrations', [
        'tenant_id' => $secondTenant->id,
        'user_id' => $secondUser->id,
        'installation_id' => $installationId,
    ]);
});

test('authenticated user can revoke their android notification installation', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    $installationId = 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b';

    putJson('/v1/me/notification-installations/'.$installationId, androidNotificationInstallationPayload(
        'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
    ))->assertCreated();

    deleteJson('/v1/me/notification-installations/'.$installationId)
        ->assertOk()
        ->assertJsonPath('data.installation_id', $installationId)
        ->assertJsonPath('data.channel', 'android_fcm');

    $this->assertDatabaseMissing('push_device_registrations', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
    ]);
});

test('session-authenticated browser user can revoke their web push notification installation', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createBrowserPushContext();

    enableWebPushNotificationChannel();
    loginBrowserSession($this, $user);

    Carbon::setTestNow(Carbon::parse('2026-05-30 12:34:56 UTC'));

    $installationId = 'b1c2d3e4-f5a6-4789-8abc-1d2e3f4a5b6c';

    $this->withHeaders(spaCsrfHeaders($this))
        ->putJson('/v1/me/notification-installations/'.$installationId, webPushPayload(
            'https://fcm.googleapis.com/fcm/send/cVJmVnB1c2g6MTIzNDU2Nzg5MA:APA91bHabcdefghijklmno1234567890'
        ))
        ->assertCreated();

    $response = $this->withHeaders(spaCsrfHeaders($this))
        ->deleteJson('/v1/me/notification-installations/'.$installationId);

    $response->assertOk()
        ->assertJsonPath('data.installation_id', $installationId)
        ->assertJsonPath('data.channel', 'web_push');

    expect($response->json('data.revoked_at'))->toBe('2026-05-30T12:34:56Z');

    $this->assertDatabaseMissing('push_device_registrations', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
    ]);

    Carbon::setTestNow();
});

test('revoking a missing notification installation returns not found', function (): void {
    ['user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    deleteJson('/v1/me/notification-installations/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b')
        ->assertNotFound();
});

test('android notification installation is rejected when the deployment disables the channel', function (): void {
    ['user' => $user] = createPushDeviceContext();

    config([
        'bootstrap.features.notification_channels.android_fcm' => false,
    ]);

    actingAs($user, 'sanctum');

    putJson('/v1/me/notification-installations/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b', androidNotificationInstallationPayload(
        'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
    ))
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'This deployment does not accept authenticated notification installations for the requested channel.',
            'code' => 'NOTIFICATION_CHANNEL_UNSUPPORTED',
            'details' => [
                'channel' => 'android_fcm',
                'retryable' => false,
            ],
        ]);
});

test('web push notification installation is rejected when the deployment disables the channel', function (): void {
    ['user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    putJson('/v1/me/notification-installations/b1c2d3e4-f5a6-4789-8abc-1d2e3f4a5b6c', webPushPayload(
        'https://fcm.googleapis.com/fcm/send/cVJmVnB1c2g6MTIzNDU2Nzg5MA:APA91bHabcdefghijklmno1234567890'
    ))
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'This deployment does not accept authenticated notification installations for the requested channel.',
            'code' => 'NOTIFICATION_CHANNEL_UNSUPPORTED',
            'details' => [
                'channel' => 'web_push',
                'retryable' => false,
            ],
        ]);
});

test('android notification installation rejects stale bootstrap metadata', function (): void {
    ['user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    putJson('/v1/me/notification-installations/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b', [
        ...androidNotificationInstallationPayload(
            'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
        ),
        'runtime' => [
            'bootstrap_version' => 'v1',
            'schema_version' => 4,
            'metadata_revision' => 2,
        ],
    ])
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'Notification runtime metadata changed; refresh bootstrap before retrying this installation update.',
            'code' => 'NOTIFICATION_RUNTIME_STATE_INVALID',
            'details' => [
                'bootstrap_version' => 'v1',
                'schema_version' => 4,
                'channel' => 'android_fcm',
                'provided_metadata_revision' => 2,
                'expected_metadata_revision' => 3,
            ],
        ]);
});

test('web push notification installation rejects stale bootstrap metadata', function (): void {
    ['user' => $user] = createPushDeviceContext();

    enableWebPushNotificationChannel();
    actingAs($user, 'sanctum');

    putJson('/v1/me/notification-installations/b1c2d3e4-f5a6-4789-8abc-1d2e3f4a5b6c', [
        ...webPushPayload('https://fcm.googleapis.com/fcm/send/cVJmVnB1c2g6MTIzNDU2Nzg5MA:APA91bHabcdefghijklmno1234567890'),
        'runtime' => [
            'bootstrap_version' => 'v1',
            'schema_version' => 4,
            'metadata_revision' => 4,
        ],
    ])
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'Notification runtime metadata changed; refresh bootstrap before retrying this installation update.',
            'code' => 'NOTIFICATION_RUNTIME_STATE_INVALID',
            'details' => [
                'bootstrap_version' => 'v1',
                'schema_version' => 4,
                'channel' => 'web_push',
                'provided_metadata_revision' => 4,
                'expected_metadata_revision' => 5,
            ],
        ]);
});

test('android notification installation fails closed when android fcm runtime metadata is invalid', function (): void {
    ['user' => $user] = createPushDeviceContext();

    config([
        'bootstrap.notification_channels.android_fcm.metadata_revision' => '3.5',
    ]);

    actingAs($user, 'sanctum');

    putJson('/v1/me/notification-installations/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b', androidNotificationInstallationPayload(
        'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
    ))
        ->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Public bootstrap configuration is incomplete for this deployment.',
            'code' => 'BOOTSTRAP_STATE_INVALID',
            'details' => [
                'missing_fields' => [
                    'notification_channels.android_fcm.metadata_revision (present but invalid; must be a positive integer)',
                ],
            ],
        ]);
});

test('web push notification installation fails closed when web push runtime metadata is invalid', function (): void {
    ['user' => $user] = createPushDeviceContext();

    enableWebPushNotificationChannel();
    config([
        'bootstrap.notification_channels.web_push.metadata_revision' => '5.5',
    ]);

    actingAs($user, 'sanctum');

    putJson('/v1/me/notification-installations/b1c2d3e4-f5a6-4789-8abc-1d2e3f4a5b6c', webPushPayload(
        'https://fcm.googleapis.com/fcm/send/cVJmVnB1c2g6MTIzNDU2Nzg5MA:APA91bHabcdefghijklmno1234567890'
    ))
        ->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Public bootstrap configuration is incomplete for this deployment.',
            'code' => 'BOOTSTRAP_STATE_INVALID',
            'details' => [
                'missing_fields' => [
                    'notification_channels.web_push.metadata_revision (present but invalid; must be a positive integer)',
                ],
            ],
        ]);
});

test('android notification installation validates malformed payloads', function (): void {
    ['user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    putJson('/v1/me/notification-installations/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b', [
        ...androidNotificationInstallationPayload('too-short'),
        'registration' => [
            'push_token' => 'too-short',
            'app' => [
                'package_name' => 'com.example.invalid',
                'package_version_name' => '1.5.0',
                'package_version_code' => 10500,
            ],
            'device' => [
                'manufacturer' => 'Samsung',
                'model' => 'SM-G556B',
                'android_version' => '16',
                'sdk_int' => 36,
            ],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'registration.push_token',
            'registration.app.package_name',
        ]);
});

test('web push notification installation validates malformed payloads', function (): void {
    ['user' => $user] = createPushDeviceContext();

    enableWebPushNotificationChannel();
    actingAs($user, 'sanctum');

    putJson('/v1/me/notification-installations/b1c2d3e4-f5a6-4789-8abc-1d2e3f4a5b6c', [
        ...webPushPayload('not-a-valid-url'),
        'registration' => [
            'browser' => [
                'browser_name' => 'Chrome',
                'browser_version' => '136.0.7103.114',
                'service_worker_scope' => '/',
            ],
            'subscription' => [
                'endpoint' => 'not-a-valid-url',
                'expiration_time' => 1782475200000,
                'keys' => [
                    'p256dh' => 'short',
                    'auth' => 'tiny',
                ],
            ],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'registration.subscription.endpoint',
            'registration.subscription.keys.p256dh',
            'registration.subscription.keys.auth',
        ]);
});

test('web push notification installation rejects non-https subscription endpoints', function (): void {
    ['user' => $user] = createPushDeviceContext();

    enableWebPushNotificationChannel();
    actingAs($user, 'sanctum');

    putJson('/v1/me/notification-installations/b1c2d3e4-f5a6-4789-8abc-1d2e3f4a5b6c', [
        ...webPushPayload('http://preview.secpal.dev/push/mock-subscription'),
        'registration' => [
            'browser' => [
                'browser_name' => 'Chrome',
                'browser_version' => '136.0.7103.114',
                'service_worker_scope' => '/',
            ],
            'subscription' => [
                'endpoint' => 'http://preview.secpal.dev/push/mock-subscription',
                'expiration_time' => 1782475200000,
                'keys' => [
                    'p256dh' => 'BElx7P1qA2rS9tUvWxYz0123456789abcdefghijklmnopqrstuv',
                    'auth' => 'K7d9Lm2PqRs',
                ],
            ],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'registration.subscription.endpoint',
        ]);
});
