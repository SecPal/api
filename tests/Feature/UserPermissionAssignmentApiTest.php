<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * @return array{
 *     tenant: TenantKey,
 *     registrar: PermissionRegistrar,
 *     crossTenantUser: User
 * }
 */
function createUserPermissionAssignmentContext(): array
{
    $keys = TenantKey::generateEnvelopeKeys();
    $tenant = TenantKey::create($keys);

    /** @var PermissionRegistrar $registrar */
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    Permission::create(['name' => 'employees.read', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'employees.export', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'reports.generate', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'shifts.read', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'permissions.read', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'permissions.assign_direct', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'permissions.revoke_direct', 'guard_name' => 'sanctum']);

    $managerRole = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);
    $managerRole->givePermissionTo(['employees.read', 'shifts.read']);

    $adminRole = Role::create(['name' => 'Admin', 'guard_name' => 'sanctum']);
    $adminRole->givePermissionTo([
        'permissions.read',
        'permissions.assign_direct',
        'permissions.revoke_direct',
    ]);

    $otherTenantKeys = TenantKey::generateEnvelopeKeys();
    $otherTenant = TenantKey::create($otherTenantKeys);
    $crossTenantUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

    return [
        'tenant' => $tenant,
        'registrar' => $registrar,
        'crossTenantUser' => $crossTenantUser,
    ];
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('user can view own permissions via_roles and direct and all', function () {
    createUserPermissionAssignmentContext();

    $user = User::factory()->create();
    $user->assignRole('Manager');
    $user->givePermissionTo('employees.export');

    actingAs($user, 'sanctum');

    $response = getJson("/v1/users/{$user->id}/permissions");

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'via_roles' => [
                    '*' => ['name', 'role'],
                ],
                'direct' => [
                    '*' => ['name', 'valid_from', 'valid_until', 'assigned_at'],
                ],
                'all',
            ],
        ])
        ->assertJsonPath('data.all', fn ($all) => in_array('employees.read', $all)
            && in_array('shifts.read', $all)
            && in_array('employees.export', $all));
});

test('admin can view any user permissions', function () {
    createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('Manager');

    actingAs($admin, 'sanctum');

    $response = getJson("/v1/users/{$targetUser->id}/permissions");

    $response->assertOk()
        ->assertJsonStructure(['data' => ['via_roles', 'direct', 'all']]);
});

test('admin gets 404 when viewing cross-tenant user permissions', function () {
    ['crossTenantUser' => $crossTenantUser] = createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    actingAs($admin, 'sanctum');

    $response = getJson("/v1/users/{$crossTenantUser->id}/permissions");

    $response->assertNotFound();
});

test('user cannot view other user permissions', function () {
    createUserPermissionAssignmentContext();

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    actingAs($user, 'sanctum');

    $response = getJson("/v1/users/{$otherUser->id}/permissions");

    $response->assertForbidden();
});

test('admin can assign direct permission to user', function () {
    createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();

    actingAs($admin, 'sanctum');

    $response = postJson("/v1/users/{$targetUser->id}/permissions", [
        'permissions' => ['employees.export'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.0.name', 'employees.export');

    expect($targetUser->fresh()->hasDirectPermission('employees.export'))->toBeTrue();
});

test('admin gets 404 when assigning permission to cross-tenant user', function () {
    ['crossTenantUser' => $crossTenantUser] = createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    actingAs($admin, 'sanctum');

    $response = postJson("/v1/users/{$crossTenantUser->id}/permissions", [
        'permissions' => ['employees.export'],
    ]);

    $response->assertNotFound();
});

test('admin can assign direct permission with temporal constraints', function () {
    createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();

    $validFrom = now()->toIso8601String();
    $validUntil = now()->addDays(7)->toIso8601String();

    actingAs($admin, 'sanctum');

    $response = postJson("/v1/users/{$targetUser->id}/permissions", [
        'permissions' => ['reports.generate'],
        'valid_from' => $validFrom,
        'valid_until' => $validUntil,
        'reason' => 'Temporary report access',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.0.name', 'reports.generate')
        ->assertJsonPath('data.0.valid_from', $validFrom)
        ->assertJsonPath('data.0.valid_until', $validUntil);
});

test('non-admin cannot assign permissions', function () {
    createUserPermissionAssignmentContext();

    $user = User::factory()->create();
    $targetUser = User::factory()->create();

    actingAs($user, 'sanctum');

    $response = postJson("/v1/users/{$targetUser->id}/permissions", [
        'permissions' => ['employees.export'],
    ]);

    $response->assertForbidden();
});

test('admin can revoke direct permission from user', function () {
    createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();
    $targetUser->givePermissionTo('employees.export');

    expect($targetUser->hasDirectPermission('employees.export'))->toBeTrue();

    actingAs($admin, 'sanctum');

    $response = deleteJson("/v1/users/{$targetUser->id}/permissions/employees.export");

    $response->assertOk();

    expect($targetUser->fresh()->hasDirectPermission('employees.export'))->toBeFalse();
});

test('admin gets 404 when revoking permission from cross-tenant user', function () {
    ['crossTenantUser' => $crossTenantUser] = createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    actingAs($admin, 'sanctum');

    $response = deleteJson("/v1/users/{$crossTenantUser->id}/permissions/employees.export");

    $response->assertNotFound();
});

test('revoking direct permission does not affect role permissions', function () {
    createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('Manager');
    $targetUser->givePermissionTo('employees.read');

    actingAs($admin, 'sanctum');

    $response = deleteJson("/v1/users/{$targetUser->id}/permissions/employees.read");

    $response->assertOk();

    expect($targetUser->fresh()->hasDirectPermission('employees.read'))->toBeFalse()
        ->and($targetUser->hasPermissionTo('employees.read'))->toBeTrue();
});

test('user can view only direct permissions', function () {
    createUserPermissionAssignmentContext();

    $user = User::factory()->create();
    $user->assignRole('Manager');
    $user->givePermissionTo('employees.export');

    actingAs($user, 'sanctum');

    $response = getJson("/v1/users/{$user->id}/permissions/direct");

    $response->assertOk()
        ->assertJsonCount(1, 'data.direct')
        ->assertJsonPath('data.direct.0.name', 'employees.export');
});

test('admin gets 404 when viewing direct permissions for cross-tenant user', function () {
    ['crossTenantUser' => $crossTenantUser] = createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    actingAs($admin, 'sanctum');

    $response = getJson("/v1/users/{$crossTenantUser->id}/permissions/direct");

    $response->assertNotFound();
});

test('unauthenticated user cannot access permissions endpoints', function () {
    createUserPermissionAssignmentContext();

    $user = User::factory()->create();

    getJson("/v1/users/{$user->id}/permissions")
        ->assertUnauthorized();

    postJson("/v1/users/{$user->id}/permissions", [
        'permissions' => ['employees.read'],
    ])->assertUnauthorized();

    deleteJson("/v1/users/{$user->id}/permissions/employees.read")
        ->assertUnauthorized();

    getJson("/v1/users/{$user->id}/permissions/direct")
        ->assertUnauthorized();
});

test('validation fails when permissions array is empty', function () {
    createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();

    actingAs($admin, 'sanctum');

    $response = postJson("/v1/users/{$targetUser->id}/permissions", [
        'permissions' => [],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions']);
});

test('validation fails when permission does not exist', function () {
    createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();

    actingAs($admin, 'sanctum');

    $response = postJson("/v1/users/{$targetUser->id}/permissions", [
        'permissions' => ['nonexistent.permission'],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions.0']);
});

test('validation fails when valid_until is before valid_from', function () {
    createUserPermissionAssignmentContext();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();

    actingAs($admin, 'sanctum');

    $response = postJson("/v1/users/{$targetUser->id}/permissions", [
        'permissions' => ['employees.export'],
        'valid_from' => now()->addDays(7)->toIso8601String(),
        'valid_until' => now()->toIso8601String(),
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['valid_until']);
});
