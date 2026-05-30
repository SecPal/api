<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Permission;
use App\Models\TemporalRoleUser;
use App\Models\TenantKey;
use App\Models\User;
use App\Support\ApiTimestamp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

function seedRoleApiPermissions(): void
{
    foreach (['role.assign', 'role.revoke', 'role.read'] as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'sanctum']);
    }
}

function createRoleApiRole(string $name, string $guardName = 'sanctum'): Role
{
    $attributes = [
        'name' => $name,
        'guard_name' => $guardName,
    ];

    $teamForeignKey = config('permission.column_names.team_foreign_key');
    $teamId = app(PermissionRegistrar::class)->getPermissionsTeamId();

    if (is_string($teamForeignKey) && $teamForeignKey !== '' && $teamId !== null && $guardName === 'sanctum') {
        $attributes[$teamForeignKey] = $teamId;
    }

    $role = Role::firstOrCreate($attributes);
    $role->syncPermissions([]);

    return $role;
}

function resetRoleApiRbacState(): void
{
    DB::table('role_has_permissions')->delete();
    DB::table('model_has_roles')->delete();
    DB::table('model_has_permissions')->delete();
    Role::query()->delete();
    Permission::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * @return array{
 *     tenant: TenantKey,
 *     registrar: PermissionRegistrar,
 *     user: User,
 *     targetUser: User,
 *     role: Role,
 *     otherTenant: TenantKey,
 *     crossTenantUser: User
 * }
 */
function createRoleApiContext(): array
{
    $keys = TenantKey::generateEnvelopeKeys();
    $tenant = TenantKey::create($keys);

    /** @var PermissionRegistrar $registrar */
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);
    resetRoleApiRbacState();

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $targetUser = User::factory()->create(['tenant_id' => $tenant->id]);
    $role = createRoleApiRole('manager');

    $otherTenantKeys = TenantKey::generateEnvelopeKeys();
    $otherTenant = TenantKey::create($otherTenantKeys);
    $crossTenantUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

    seedRoleApiPermissions();

    return [
        'tenant' => $tenant,
        'registrar' => $registrar,
        'user' => $user,
        'targetUser' => $targetUser,
        'role' => $role,
        'otherTenant' => $otherTenant,
        'crossTenantUser' => $crossTenantUser,
    ];
}

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

test('role controller accepts UUID string parameters', function (): void {
    ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

    $user->givePermissionTo('role.assign');
    actingAs($user, 'sanctum');

    expect($targetUser->id)->toBeString();
    expect($targetUser->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');

    $response = postJson("/v1/users/{$targetUser->id}/roles", [
        'role' => 'manager',
        'valid_from' => now()->toIso8601String(),
        'valid_until' => now()->addDays(7)->toIso8601String(),
    ]);

    $response->assertCreated();
});

describe('POST /v1/users/{id}/roles - Assign Role', function () {
    test('returns 401 when not authenticated', function (): void {
        ['targetUser' => $targetUser] = createRoleApiContext();

        $response = postJson("/v1/users/{$targetUser->id}/roles", [
            'role' => 'manager',
            'valid_from' => now()->toIso8601String(),
            'valid_until' => now()->addDays(7)->toIso8601String(),
        ]);

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks role.assign permission', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        actingAs($user, 'sanctum');

        $response = postJson("/v1/users/{$targetUser->id}/roles", [
            'role' => 'manager',
            'valid_from' => now()->toIso8601String(),
            'valid_until' => now()->addDays(7)->toIso8601String(),
        ]);

        $response->assertForbidden();
    });

    test('returns 422 when role is missing', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        $response = postJson("/v1/users/{$targetUser->id}/roles", [
            'valid_from' => now()->toIso8601String(),
            'valid_until' => now()->addDays(7)->toIso8601String(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    });

    test('returns 422 when valid_until is before valid_from', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        $response = postJson("/v1/users/{$targetUser->id}/roles", [
            'role' => 'manager',
            'valid_from' => now()->addDays(7)->toIso8601String(),
            'valid_until' => now()->toIso8601String(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);
    });

    test('assigns role with temporal parameters and returns 201', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        $validFrom = now();
        $validUntil = now()->addDays(7);

        $response = postJson("/v1/users/{$targetUser->id}/roles", [
            'role' => 'manager',
            'valid_from' => $validFrom->toIso8601String(),
            'valid_until' => $validUntil->toIso8601String(),
            'auto_revoke' => true,
            'reason' => 'Vacation coverage',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'user_id',
                'role',
                'valid_from',
                'valid_until',
                'auto_revoke',
                'reason',
            ])
            ->assertJsonFragment([
                'user_id' => $targetUser->id,
                'role' => 'manager',
                'auto_revoke' => true,
                'reason' => 'Vacation coverage',
            ]);

        expect($response->json('valid_from'))->toBe(ApiTimestamp::format($validFrom))
            ->and($response->json('valid_until'))->toBe(ApiTimestamp::format($validUntil));

        expect($targetUser->hasRole('manager'))->toBeTrue();
    });

    test('returns 404 for cross-tenant target user', function (): void {
        ['user' => $user, 'crossTenantUser' => $crossTenantUser] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        $response = postJson("/v1/users/{$crossTenantUser->id}/roles", [
            'role' => 'manager',
            'valid_from' => now()->toIso8601String(),
            'valid_until' => now()->addDays(7)->toIso8601String(),
        ]);

        $response->assertNotFound();
    });
});

describe('GET /v1/users/{id}/roles - List Roles', function () {
    test('returns 401 when not authenticated', function (): void {
        ['targetUser' => $targetUser] = createRoleApiContext();

        $response = getJson("/v1/users/{$targetUser->id}/roles");

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks role.read permission', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        actingAs($user, 'sanctum');

        $response = getJson("/v1/users/{$targetUser->id}/roles");

        $response->assertForbidden();
    });

    test('returns empty array when user has no roles', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        $user->givePermissionTo('role.read');
        actingAs($user, 'sanctum');

        $response = getJson("/v1/users/{$targetUser->id}/roles");

        $response->assertOk()
            ->assertJson(['roles' => []]);
    });

    test('returns roles with expiry info when user has roles', function (): void {
        ['tenant' => $tenant, 'user' => $user, 'targetUser' => $targetUser, 'role' => $role] = createRoleApiContext();

        $user->givePermissionTo('role.read');
        actingAs($user, 'sanctum');

        $validFrom = now()->subHours(1);
        $validUntil = now()->addDays(7);

        assignTemporalRole($targetUser, $role, $tenant->id, [
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'auto_revoke' => true,
            'assigned_by' => $user->id,
            'reason' => 'Test assignment',
        ]);

        $response = getJson("/v1/users/{$targetUser->id}/roles");

        $response->assertOk()
            ->assertJsonStructure([
                'roles' => [
                    '*' => [
                        'role',
                        'valid_from',
                        'valid_until',
                        'auto_revoke',
                        'is_active',
                        'is_expired',
                    ],
                ],
            ])
            ->assertJsonFragment([
                'role' => 'manager',
                'auto_revoke' => true,
                'is_active' => true,
                'is_expired' => false,
            ]);

        expect($response->json('roles.0.valid_from'))->toBe(ApiTimestamp::format($validFrom))
            ->and($response->json('roles.0.valid_until'))->toBe(ApiTimestamp::format($validUntil));
    });

    test('returns 404 for cross-tenant target user', function (): void {
        ['user' => $user, 'crossTenantUser' => $crossTenantUser] = createRoleApiContext();

        $user->givePermissionTo('role.read');
        actingAs($user, 'sanctum');

        $response = getJson("/v1/users/{$crossTenantUser->id}/roles");

        $response->assertNotFound();
    });
});

describe('DELETE /v1/users/{id}/roles/{role} - Revoke Role', function () {
    test('returns 401 when not authenticated', function (): void {
        ['targetUser' => $targetUser] = createRoleApiContext();

        $response = deleteJson("/v1/users/{$targetUser->id}/roles/manager");

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks role.revoke permission', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        actingAs($user, 'sanctum');

        $response = deleteJson("/v1/users/{$targetUser->id}/roles/manager");

        $response->assertForbidden();
    });

    test('returns 404 when role not assigned to user', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        $user->givePermissionTo('role.revoke');
        actingAs($user, 'sanctum');

        $response = deleteJson("/v1/users/{$targetUser->id}/roles/manager");

        $response->assertNotFound();
    });

    test('revokes role and returns 204', function (): void {
        ['tenant' => $tenant, 'user' => $user, 'targetUser' => $targetUser, 'role' => $role] = createRoleApiContext();

        $user->givePermissionTo('role.revoke');
        actingAs($user, 'sanctum');

        assignTemporalRole($targetUser, $role, $tenant->id, [
            'valid_from' => now(),
            'valid_until' => now()->addDays(7),
            'auto_revoke' => true,
            'assigned_by' => $user->id,
        ]);

        expect($targetUser->hasRole('manager'))->toBeTrue();

        $response = deleteJson("/v1/users/{$targetUser->id}/roles/manager");

        $response->assertNoContent();

        $targetUser->refresh();
        expect($targetUser->hasRole('manager'))->toBeFalse();
    });

    test('returns 404 for cross-tenant target user', function (): void {
        ['user' => $user, 'crossTenantUser' => $crossTenantUser] = createRoleApiContext();

        $user->givePermissionTo('role.revoke');
        actingAs($user, 'sanctum');

        $response = deleteJson("/v1/users/{$crossTenantUser->id}/roles/manager");

        $response->assertNotFound();
    });
});

describe('PATCH /v1/users/{id}/roles/{role}/extend - Extend Role', function () {
    test('returns 401 when not authenticated', function (): void {
        ['targetUser' => $targetUser] = createRoleApiContext();

        $response = patchJson("/v1/users/{$targetUser->id}/roles/manager/extend", [
            'valid_until' => now()->addDays(14)->toIso8601String(),
        ]);

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks role.assign permission', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        actingAs($user, 'sanctum');

        $response = patchJson("/v1/users/{$targetUser->id}/roles/manager/extend", [
            'valid_until' => now()->addDays(14)->toIso8601String(),
        ]);

        $response->assertForbidden();
    });

    test('returns 404 when role not assigned to user', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        $response = patchJson("/v1/users/{$targetUser->id}/roles/manager/extend", [
            'valid_until' => now()->addDays(14)->toIso8601String(),
        ]);

        $response->assertNotFound();
    });

    test('returns 422 when new valid_until is before current valid_until', function (): void {
        ['tenant' => $tenant, 'user' => $user, 'targetUser' => $targetUser, 'role' => $role] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        assignTemporalRole($targetUser, $role, $tenant->id, [
            'valid_from' => now(),
            'valid_until' => now()->addDays(14),
            'auto_revoke' => true,
            'assigned_by' => $user->id,
        ]);

        $response = patchJson("/v1/users/{$targetUser->id}/roles/manager/extend", [
            'valid_until' => now()->addDays(7)->toIso8601String(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);
    });

    test('extends role expiration and returns 200', function (): void {
        ['tenant' => $tenant, 'user' => $user, 'targetUser' => $targetUser, 'role' => $role] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        $originalValidUntil = now()->addDays(7);
        $newValidUntil = now()->addDays(14);

        assignTemporalRole($targetUser, $role, $tenant->id, [
            'valid_from' => now(),
            'valid_until' => $originalValidUntil,
            'auto_revoke' => true,
            'assigned_by' => $user->id,
        ]);

        $response = patchJson("/v1/users/{$targetUser->id}/roles/manager/extend", [
            'valid_until' => $newValidUntil->toIso8601String(),
            'reason' => 'Extended vacation period',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user_id',
                'role',
                'valid_from',
                'valid_until',
                'reason',
            ])
            ->assertJsonFragment([
                'user_id' => $targetUser->id,
                'role' => 'manager',
                'reason' => 'Extended vacation period',
            ]);

        $assignment = TemporalRoleUser::where('model_id', $targetUser->id)
            ->where('role_id', $role->id)
            ->first();

        expect($response->json('valid_from'))->toBe(ApiTimestamp::format($assignment->valid_from))
            ->and($response->json('valid_until'))->toBe(ApiTimestamp::format($newValidUntil));

        expect($assignment->valid_until->toDateString())
            ->toBe($newValidUntil->toDateString());
    });

    test('returns 404 for cross-tenant target user', function (): void {
        ['user' => $user, 'crossTenantUser' => $crossTenantUser] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        $response = patchJson("/v1/users/{$crossTenantUser->id}/roles/manager/extend", [
            'valid_until' => now()->addDays(14)->toIso8601String(),
        ]);

        $response->assertNotFound();
    });
});

describe('Edge Cases - Temporal Date Validation', function () {
    test('accepts assignment with valid_from in the past and valid_until also past', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        $response = postJson("/v1/users/{$targetUser->id}/roles", [
            'role' => 'manager',
            'valid_from' => now()->subDays(10)->toIso8601String(),
            'valid_until' => now()->subDays(5)->toIso8601String(),
        ]);

        $response->assertCreated();
    });

    test('accepts assignment with only valid_from (no end date - unbegrenzt)', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        $response = postJson("/v1/users/{$targetUser->id}/roles", [
            'role' => 'manager',
            'valid_from' => now()->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'role' => 'manager',
            ]);
    });

    test('accepts assignment with only valid_until (no start date)', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        $response = postJson("/v1/users/{$targetUser->id}/roles", [
            'role' => 'manager',
            'valid_until' => now()->addDays(7)->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'role' => 'manager',
            ]);
    });

    test('accepts assignment with neither valid_from nor valid_until (permanent role)', function (): void {
        ['user' => $user, 'targetUser' => $targetUser] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        $response = postJson("/v1/users/{$targetUser->id}/roles", [
            'role' => 'manager',
        ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'role' => 'manager',
            ]);
    });

    test('rejects extension with past date', function (): void {
        ['tenant' => $tenant, 'user' => $user, 'targetUser' => $targetUser, 'role' => $role] = createRoleApiContext();

        $user->givePermissionTo('role.assign');
        actingAs($user, 'sanctum');

        assignTemporalRole($targetUser, $role, $tenant->id, [
            'valid_from' => now(),
            'valid_until' => now()->addDays(7),
            'assigned_by' => $user->id,
        ]);

        $response = patchJson("/v1/users/{$targetUser->id}/roles/manager/extend", [
            'valid_until' => now()->subDay()->toIso8601String(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);
    });
});

describe('Edge Cases - N+1 Query Prevention', function () {
    test('fetches multiple role assignments efficiently', function (): void {
        ['tenant' => $tenant, 'registrar' => $registrar, 'user' => $user, 'targetUser' => $targetUser, 'role' => $role] = createRoleApiContext();

        $user->givePermissionTo('role.read');
        actingAs($user, 'sanctum');

        $registrar->setPermissionsTeamId($tenant->id);

        $roles = [
            createRoleApiRole('regional_manager'),
            createRoleApiRole('editor'),
            createRoleApiRole('viewer'),
        ];

        assignTemporalRole($targetUser, $role, $tenant->id, [
            'valid_from' => now(),
            'valid_until' => now()->addDays(30),
            'assigned_by' => $user->id,
        ]);

        foreach ($roles as $extraRole) {
            assignTemporalRole($targetUser, $extraRole, $tenant->id, [
                'valid_from' => now(),
                'valid_until' => now()->addDays(30),
                'assigned_by' => $user->id,
            ]);
        }

        DB::enableQueryLog();

        $response = getJson("/v1/users/{$targetUser->id}/roles");

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect(count($queries))->toBeLessThan(10);

        $response->assertOk()
            ->assertJsonStructure([
                'roles' => [
                    '*' => ['role', 'valid_from', 'valid_until', 'is_active', 'is_expired'],
                ],
            ]);

        $data = $response->json();
        expect($data['roles'])->toHaveCount(4);
    });
});
