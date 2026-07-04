<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AndroidEnrollmentSession;
use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function androidProvisioningProfile(): array
{
    return [
        'kiosk_mode_enabled' => true,
        'lock_task_enabled' => true,
        'allow_phone' => false,
        'allow_sms' => false,
        'prefer_gesture_navigation' => true,
        'allowed_packages' => ['com.example.approvedapp'],
    ];
}

/**
 * @return array{tenant: TenantKey, admin: User, reader: User, outsider: User}
 */
function createAndroidEnrollmentApiContext(): array
{
    $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $reader = User::factory()->create(['tenant_id' => $tenant->id]);
    $outsider = User::factory()->create(['tenant_id' => $tenant->id]);

    /** @var PermissionRegistrar $registrar */
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    Permission::firstOrCreate(['name' => 'android_enrollment.read', 'guard_name' => 'sanctum']);
    Permission::firstOrCreate(['name' => 'android_enrollment.write', 'guard_name' => 'sanctum']);

    givePermissionWithTenant($admin, $tenant->id, 'android_enrollment.read');
    givePermissionWithTenant($admin, $tenant->id, 'android_enrollment.write');
    givePermissionWithTenant($reader, $tenant->id, 'android_enrollment.read');

    return [
        'tenant' => $tenant,
        'admin' => $admin,
        'reader' => $reader,
        'outsider' => $outsider,
    ];
}

test('authorized user can create android enrollment session and receives private provisioning payload', function (): void {
    ['tenant' => $tenant, 'admin' => $admin] = createAndroidEnrollmentApiContext();

    actingAs($admin, 'sanctum');

    $response = postJson('/v1/android-enrollment-sessions', [
        'device_label' => 'Front desk kiosk',
        'update_channel' => 'managed_device',
        'expires_in_minutes' => 15,
        'notes' => 'Provision during customer-site handoff.',
        'provisioning_profile' => androidProvisioningProfile(),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.session.status', 'pending')
        ->assertJsonPath('data.session.enrollment_mode', 'device_owner')
        ->assertJsonPath('data.session.update_channel', 'managed_device')
        ->assertJsonPath('data.session.device_label', 'Front desk kiosk')
        ->assertJsonStructure([
            'data' => [
                'session' => [
                    'id',
                    'status',
                    'enrollment_mode',
                    'update_channel',
                    'release_metadata_url',
                    'provisioning_profile',
                    'bootstrap_token_expires_at',
                ],
                'provisioning_qr_payload' => [
                    'android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME',
                    'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION',
                    'android.app.extra.PROVISIONING_DEVICE_ADMIN_SIGNATURE_CHECKSUM',
                    'android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE' => [
                        'bootstrap_token',
                        'enrollment_session_id',
                    ],
                ],
            ],
        ]);

    $provisioningQrPayload = $response->json('data.provisioning_qr_payload');

    expect($provisioningQrPayload['android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION'] ?? null)
        ->toBe('https://apk.secpal.app/android/channels/managed_device/app.secpal-latest.apk');

    $session = AndroidEnrollmentSession::query()->latest()->first();

    expect($session)->not->toBeNull()
        ->and($session?->tenant_id)->toBe($tenant->id)
        ->and($session?->created_by)->toBe($admin->id)
        ->and($session?->bootstrap_token_lookup_hash)->not->toBeNull();

    $bootstrapToken = $response->json('data.provisioning_qr_payload')['android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE']['bootstrap_token'] ?? null;

    expect(is_string($bootstrapToken))->toBeTrue()
        ->and(Hash::check($bootstrapToken, $session?->bootstrap_token ?? ''))->toBeTrue();

    $activity = Activity::query()
        ->where('description', 'Created Android enrollment session')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($admin->id)
        ->and($activity?->subject_id)->toBe($session?->id)
        ->and($activity?->properties['event'])->toBe('android_enrollment_session_created');
});

test('provisioning payload download URL reflects the session update_channel', function (): void {
    ['admin' => $admin] = createAndroidEnrollmentApiContext();

    actingAs($admin, 'sanctum');

    $response = postJson('/v1/android-enrollment-sessions', [
        'device_label' => 'BYOD device',
        'update_channel' => 'direct_apk',
        'expires_in_minutes' => 15,
        'provisioning_profile' => androidProvisioningProfile(),
    ]);

    $response->assertCreated();

    expect($response->json('data.provisioning_qr_payload')['android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION'] ?? null)
        ->toBe('https://apk.secpal.app/android/channels/direct_apk/app.secpal-latest.apk');
});

test('reader can list sessions without receiving raw bootstrap tokens', function (): void {
    ['admin' => $admin, 'reader' => $reader] = createAndroidEnrollmentApiContext();

    AndroidEnrollmentSession::generate($admin, [
        'device_label' => 'Reception tablet',
        'update_channel' => 'managed_device',
        'provisioning_profile' => androidProvisioningProfile(),
    ]);

    actingAs($reader, 'sanctum');

    $response = getJson('/v1/android-enrollment-sessions');

    $response->assertOk()
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonMissingPath('data.0.bootstrap_token')
        ->assertJsonMissingPath('data.0.provisioning_qr_payload');
});

test('user without write permission cannot create or revoke android enrollment sessions', function (): void {
    ['admin' => $admin, 'outsider' => $outsider] = createAndroidEnrollmentApiContext();

    $issued = AndroidEnrollmentSession::generate($admin, [
        'update_channel' => 'managed_device',
        'provisioning_profile' => androidProvisioningProfile(),
    ]);

    actingAs($outsider, 'sanctum');

    postJson('/v1/android-enrollment-sessions', [
        'update_channel' => 'managed_device',
        'provisioning_profile' => androidProvisioningProfile(),
    ])->assertForbidden();

    postJson('/v1/android-enrollment-sessions/'.$issued['model']->id.'/revoke', [
        'reason' => 'Not allowed',
    ])->assertForbidden();
});

test('authorized user can revoke pending android enrollment session and the action is audited', function (): void {
    ['admin' => $admin] = createAndroidEnrollmentApiContext();

    $issued = AndroidEnrollmentSession::generate($admin, [
        'device_label' => 'Site kiosk',
        'update_channel' => 'managed_device',
        'provisioning_profile' => androidProvisioningProfile(),
    ]);

    actingAs($admin, 'sanctum');

    $response = postJson('/v1/android-enrollment-sessions/'.$issued['model']->id.'/revoke', [
        'reason' => 'Device handoff canceled.',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'revoked')
        ->assertJsonPath('data.revocation_reason', 'Device handoff canceled.');

    $issued['model']->refresh();

    expect($issued['model']->revoked_at)->not->toBeNull()
        ->and($issued['model']->revocation_reason)->toBe('Device handoff canceled.');

    $activity = Activity::query()
        ->where('description', 'Revoked Android enrollment session')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($admin->id)
        ->and($activity?->subject_id)->toBe($issued['model']->id)
        ->and($activity?->properties['event'])->toBe('android_enrollment_session_revoked');
});

test('bootstrap exchange consumes valid token and rejects reused or revoked tokens', function (): void {
    ['admin' => $admin] = createAndroidEnrollmentApiContext();

    $issued = AndroidEnrollmentSession::generate($admin, [
        'update_channel' => 'managed_device',
        'provisioning_profile' => androidProvisioningProfile(),
    ]);

    $response = postJson('/v1/android/bootstrap/exchange', [
        'bootstrap_token' => $issued['plain'],
        'package_name' => 'app.secpal',
        'package_version_name' => '1.4.0',
        'package_version_code' => 10400,
        'device_name' => 'SM-G556B reception tablet',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.enrollment_session_id', $issued['model']->id)
        ->assertJsonPath('data.update_channel', 'managed_device')
        ->assertJsonPath('data.api_base_url', 'https://api.secpal.dev/v1');

    $issued['model']->refresh();

    expect($issued['model']->exchanged_at)->not->toBeNull()
        ->and($issued['model']->status)->toBe('exchanged');

    postJson('/v1/android/bootstrap/exchange', [
        'bootstrap_token' => $issued['plain'],
        'package_name' => 'app.secpal',
    ])->assertConflict();

    $revoked = AndroidEnrollmentSession::generate($admin, [
        'update_channel' => 'managed_device',
        'provisioning_profile' => androidProvisioningProfile(),
    ]);
    $revoked['model']->revoke('Revoked before provisioning.');

    postJson('/v1/android/bootstrap/exchange', [
        'bootstrap_token' => $revoked['plain'],
        'package_name' => 'app.secpal',
    ])->assertConflict();

    $activity = Activity::query()
        ->where('description', 'Completed Android bootstrap exchange')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->subject_id)->toBe($issued['model']->id)
        ->and($activity?->properties['event'])->toBe('android_bootstrap_exchanged');
});

test('deleted employee deprovisioning preserves exchanged and revoked android enrollment session history', function (): void {
    ['tenant' => $tenant, 'admin' => $admin] = createAndroidEnrollmentApiContext();

    $employee = App\Models\Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => App\Models\Employee::STATUS_PRE_CONTRACT,
        'user_id' => $admin->id,
        'user_account_active' => true,
    ]);

    $exchanged = AndroidEnrollmentSession::generate($admin, [
        'device_label' => 'Exchanged device',
        'update_channel' => 'managed_device',
        'provisioning_profile' => androidProvisioningProfile(),
    ])['model'];
    $exchanged->markAsExchanged('127.0.0.1', 'Pest');

    $revoked = AndroidEnrollmentSession::generate($admin, [
        'device_label' => 'Revoked device',
        'update_channel' => 'managed_device',
        'provisioning_profile' => androidProvisioningProfile(),
    ])['model'];
    $revoked->revoke('Canceled');

    app(App\Services\EmployeeLifecycleService::class)->delete($employee);

    expect(AndroidEnrollmentSession::query()->find($exchanged->id))->not->toBeNull()
        ->and(AndroidEnrollmentSession::query()->find($exchanged->id)?->created_by)->toBeNull()
        ->and(AndroidEnrollmentSession::query()->find($exchanged->id)?->exchanged_at)->not->toBeNull()
        ->and(AndroidEnrollmentSession::query()->find($revoked->id))->not->toBeNull()
        ->and(AndroidEnrollmentSession::query()->find($revoked->id)?->created_by)->toBeNull()
        ->and(AndroidEnrollmentSession::query()->find($revoked->id)?->revoked_at)->not->toBeNull();
});

test('deleted employee deprovisioning revokes pending android enrollment sessions', function (): void {
    ['tenant' => $tenant, 'admin' => $admin] = createAndroidEnrollmentApiContext();

    $employee = App\Models\Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => App\Models\Employee::STATUS_PRE_CONTRACT,
        'user_id' => $admin->id,
        'user_account_active' => true,
    ]);

    $issued = AndroidEnrollmentSession::generate($admin, [
        'device_label' => 'Pending device',
        'update_channel' => 'managed_device',
        'provisioning_profile' => androidProvisioningProfile(),
    ]);

    app(App\Services\EmployeeLifecycleService::class)->delete($employee);

    $pending = AndroidEnrollmentSession::query()->find($issued['model']->id);

    expect($pending)->not->toBeNull()
        ->and($pending?->created_by)->toBeNull()
        ->and($pending?->revoked_at)->not->toBeNull()
        ->and($pending?->status)->toBe('revoked');

    postJson('/v1/android/bootstrap/exchange', [
        'bootstrap_token' => $issued['plain'],
        'package_name' => 'app.secpal',
    ])->assertConflict();
});
