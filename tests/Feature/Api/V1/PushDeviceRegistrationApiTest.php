<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    config([
        'bootstrap.features.android_push' => true,
        'bootstrap.features.notification_channels.android_fcm' => true,
        'bootstrap.features.notification_channels.web_push' => false,
        'bootstrap.android_push.metadata_revision' => 3,
        'bootstrap.android_push.public_client_metadata.api_key' => 'public-client-api-key-demo-1234567890',
        'bootstrap.android_push.public_client_metadata.project_id' => 'secpal-demo-push',
        'bootstrap.android_push.public_client_metadata.application_id' => '1:1234567890:android:abcdef1234567890',
        'bootstrap.android_push.public_client_metadata.sender_id' => '1234567890',
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

function pushDevicePayload(string $pushToken, string $lifecycleEvent = 'registered'): array
{
    return [
        'platform' => 'android',
        'provider' => 'fcm',
        'device_name' => 'SM-G556B reception tablet',
        'push_token' => $pushToken,
        'lifecycle_event' => $lifecycleEvent,
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
        'runtime' => [
            'bootstrap_version' => 'v1',
            'schema_version' => 2,
            'push_metadata_revision' => 3,
        ],
    ];
}

test('authenticated user can create android push device registration', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    $installationId = 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b';

    $response = putJson('/v1/me/push-devices/'.$installationId, pushDevicePayload(
        'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
    ));

    $response->assertCreated()
        ->assertJsonPath('data.installation_id', $installationId)
        ->assertJsonPath('data.platform', 'android')
        ->assertJsonPath('data.provider', 'fcm')
        ->assertJsonPath('data.device_name', 'SM-G556B reception tablet')
        ->assertJsonPath('data.token_last_eight', '90abcdef')
        ->assertJsonPath('data.last_lifecycle_event', 'registered')
        ->assertJsonPath('data.runtime.bootstrap_version', 'v1')
        ->assertJsonPath('data.runtime.schema_version', 2)
        ->assertJsonPath('data.runtime.push_metadata_revision', 3)
        ->assertJsonStructure([
            'data' => [
                'installation_id',
                'platform',
                'provider',
                'device_name',
                'token_last_eight',
                'last_lifecycle_event',
                'app' => [
                    'package_name',
                    'package_version_name',
                    'package_version_code',
                ],
                'device' => [
                    'manufacturer',
                    'model',
                    'android_version',
                    'sdk_int',
                ],
                'runtime' => [
                    'bootstrap_version',
                    'schema_version',
                    'push_metadata_revision',
                ],
                'created_at',
                'updated_at',
            ],
        ]);

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
        'schema_version' => 2,
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
});

test('android push device registration accepts integer-like numeric strings', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    $installationId = 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b';

    putJson('/v1/me/push-devices/'.$installationId, [
        ...pushDevicePayload('e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'),
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
        'runtime' => [
            'bootstrap_version' => 'v1',
            'schema_version' => '2',
            'push_metadata_revision' => '3',
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.app.package_version_code', 10500)
        ->assertJsonPath('data.device.sdk_int', 36)
        ->assertJsonPath('data.runtime.schema_version', 2)
        ->assertJsonPath('data.runtime.push_metadata_revision', 3);

    $this->assertDatabaseHas('push_device_registrations', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
        'package_version_code' => 10500,
        'sdk_int' => 36,
        'schema_version' => 2,
        'push_metadata_revision' => 3,
    ]);
});

test('repeating put updates the existing android push device registration', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    $installationId = 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b';

    putJson('/v1/me/push-devices/'.$installationId, pushDevicePayload(
        'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
    ))->assertCreated();

    $response = putJson('/v1/me/push-devices/'.$installationId, [
        ...pushDevicePayload(
            'x9y8z7w6v5u4:APA91bHfedcba0987654321QwErTyUiOpAsDfGhJkLmNoPqRsTuVwXyZfedcba09',
            'token_rotated',
        ),
        'app' => [
            'package_name' => 'app.secpal',
            'package_version_name' => '1.5.1',
            'package_version_code' => 10501,
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.installation_id', $installationId)
        ->assertJsonPath('data.token_last_eight', 'fedcba09')
        ->assertJsonPath('data.last_lifecycle_event', 'token_rotated')
        ->assertJsonPath('data.app.package_version_name', '1.5.1')
        ->assertJsonPath('data.app.package_version_code', 10501);

    $this->assertDatabaseHas('push_device_registrations', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
        'token_last_eight' => 'fedcba09',
        'last_lifecycle_event' => 'token_rotated',
        'package_version_name' => '1.5.1',
        'package_version_code' => 10501,
    ]);

    expect(DB::table('push_device_registrations')
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->where('installation_id', $installationId)
        ->count())->toBe(1);
});

test('same installation id can be registered independently in another tenant', function (): void {
    ['tenant' => $firstTenant, 'user' => $firstUser] = createPushDeviceContext();
    ['tenant' => $secondTenant, 'user' => $secondUser] = createPushDeviceContext();

    $installationId = 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b';

    actingAs($firstUser, 'sanctum');
    putJson('/v1/me/push-devices/'.$installationId, pushDevicePayload(
        'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
    ))->assertCreated();

    actingAs($secondUser, 'sanctum');
    putJson('/v1/me/push-devices/'.$installationId, pushDevicePayload(
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

test('authenticated user can revoke their android push device registration', function (): void {
    ['tenant' => $tenant, 'user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    $installationId = 'a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b';

    putJson('/v1/me/push-devices/'.$installationId, pushDevicePayload(
        'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
    ))->assertCreated();

    deleteJson('/v1/me/push-devices/'.$installationId)
        ->assertOk()
        ->assertJsonPath('data.installation_id', $installationId)
        ->assertJsonStructure([
            'data' => [
                'installation_id',
                'revoked_at',
            ],
        ]);

    $this->assertDatabaseMissing('push_device_registrations', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => $installationId,
    ]);
});

test('revoking a missing android push device registration returns not found', function (): void {
    ['user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    deleteJson('/v1/me/push-devices/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b')
        ->assertNotFound();
});

test('android push device registration is rejected when the deployment disables the feature', function (): void {
    ['user' => $user] = createPushDeviceContext();

    config([
        'bootstrap.features.android_push' => false,
        'bootstrap.features.notification_channels.android_fcm' => false,
    ]);

    actingAs($user, 'sanctum');

    putJson('/v1/me/push-devices/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b', pushDevicePayload(
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

test('android push device revocation is rejected when the deployment disables the feature', function (): void {
    ['user' => $user] = createPushDeviceContext();

    config([
        'bootstrap.features.android_push' => false,
        'bootstrap.features.notification_channels.android_fcm' => false,
    ]);

    actingAs($user, 'sanctum');

    deleteJson('/v1/me/push-devices/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b')
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

test('android push device registration rejects stale bootstrap metadata', function (): void {
    ['user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    putJson('/v1/me/push-devices/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b', [
        ...pushDevicePayload(
            'e6pGmJq4Yk12:APA91bH8mP7rQ6sT5uV4wX3yZ2aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcdef'
        ),
        'runtime' => [
            'bootstrap_version' => 'v1',
            'schema_version' => 2,
            'push_metadata_revision' => 2,
        ],
    ])
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'Notification runtime metadata changed; refresh bootstrap before retrying this installation update.',
            'code' => 'NOTIFICATION_RUNTIME_STATE_INVALID',
            'details' => [
                'bootstrap_version' => 'v1',
                'schema_version' => 2,
                'channel' => 'android_fcm',
                'provided_metadata_revision' => 2,
                'expected_metadata_revision' => 3,
            ],
        ]);
});

test('android push device registration fails closed when android fcm runtime metadata is invalid', function (): void {
    ['user' => $user] = createPushDeviceContext();

    config([
        'bootstrap.android_push.metadata_revision' => '3.5',
        'bootstrap.notification_channels.android_fcm.metadata_revision' => '3.5',
    ]);

    actingAs($user, 'sanctum');

    putJson('/v1/me/push-devices/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b', pushDevicePayload(
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

test('android push device registration validates malformed payloads', function (): void {
    ['user' => $user] = createPushDeviceContext();

    actingAs($user, 'sanctum');

    putJson('/v1/me/push-devices/a0b1c2d3-e4f5-4a67-89ab-0c1d2e3f4a5b', [
        ...pushDevicePayload('too-short'),
        'app' => [
            'package_name' => 'com.example.invalid',
            'package_version_name' => '1.5.0',
            'package_version_code' => 10500,
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'push_token',
            'app.package_name',
        ]);
});
