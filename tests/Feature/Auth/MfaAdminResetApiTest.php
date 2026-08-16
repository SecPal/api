<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Activity;
use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laragear\TwoFactor\Models\TwoFactorAuthentication;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    TwoFactorAuthentication::generateRecoveryCodesUsing();
    Cache::flush();
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * @return array{tenant: TenantKey, registrar: PermissionRegistrar, admin: User, targetUser: User, crossTenantUser: User}
 */
function createMfaAdminResetContext(): array
{
    $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());

    /** @var PermissionRegistrar $registrar */
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    Permission::create(['name' => 'users.reset_mfa', 'guard_name' => 'sanctum']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);

    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $crossTenantUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

    givePermissionWithTenant($admin, $tenant->id, 'users.reset_mfa');
    $registrar->setPermissionsTeamId($tenant->id);

    return [
        'tenant' => $tenant,
        'registrar' => $registrar,
        'admin' => $admin,
        'targetUser' => $targetUser,
        'crossTenantUser' => $crossTenantUser,
    ];
}

function enableUserMfa(User $user): void
{
    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();
}

test('privileged user can reset MFA for a same-tenant user and the action is audited', function () {
    ['admin' => $admin, 'targetUser' => $targetUser] = createMfaAdminResetContext();

    enableUserMfa($targetUser);

    $this->actingAs($admin, 'sanctum');

    $response = $this->deleteJson("/v1/users/{$targetUser->id}/mfa", [
        'reason' => 'Lost authenticator device',
    ]);

    $response->assertOk()
        ->assertJson([
            'message' => 'Two-factor authentication has been reset for the user.',
            'data' => [
                'enabled' => false,
                'method' => null,
                'recovery_codes_remaining' => 0,
            ],
        ]);

    $targetUser->refresh();

    expect($targetUser->hasTwoFactorEnabled())->toBeFalse()
        ->and($targetUser->hasPendingTwoFactorEnrollment())->toBeFalse();

    $activity = Activity::query()
        ->where('description', 'Privileged user reset multi-factor authentication')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($admin->id)
        ->and($activity?->subject_id)->toBe($targetUser->id)
        ->and($activity?->properties['event'])->toBe('mfa_reset_by_privileged_user')
        ->and($activity?->properties['reason'])->toBe('Lost authenticator device')
        ->and($activity?->properties['previous_status']['enabled'])->toBeTrue();
});

test('privileged MFA reset requires the dedicated permission', function () {
    ['tenant' => $tenant, 'targetUser' => $targetUser] = createMfaAdminResetContext();

    $unprivilegedUser = User::factory()->create(['tenant_id' => $tenant->id]);
    enableUserMfa($targetUser);

    $this->actingAs($unprivilegedUser, 'sanctum')
        ->deleteJson("/v1/users/{$targetUser->id}/mfa", [
            'reason' => 'Support request',
        ])
        ->assertForbidden();
});

test('privileged MFA reset is blocked for cross-tenant targets', function () {
    ['admin' => $admin, 'crossTenantUser' => $crossTenantUser] = createMfaAdminResetContext();

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/v1/users/{$crossTenantUser->id}/mfa", [
            'reason' => 'Support request',
        ])
        ->assertNotFound();
});

test('a privileged user cannot use the MFA reset path on their own account', function () {
    ['admin' => $admin] = createMfaAdminResetContext();

    enableUserMfa($admin);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/v1/users/{$admin->id}/mfa", [
            'reason' => 'Trying to bypass self-service flow',
        ])
        ->assertForbidden();
});
