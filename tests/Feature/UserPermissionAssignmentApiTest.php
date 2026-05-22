<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
function seedUserPermissionAssignmentPermissions(): void
{
    foreach ([
        'employees.read',
        'employees.export',
        'reports.generate',
        'shifts.read',
        'permissions.read',
        'permissions.assign_direct',
        'permissions.revoke_direct',
    ] as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'sanctum']);
    }
}

function seedUserPermissionAssignmentRoles(TenantKey $tenant): void
{
    $managerRole = Role::firstOrCreate([
        'name' => 'Manager',
        'guard_name' => 'sanctum',
        'tenant_id' => $tenant->id,
    ]);

    $missingManagerPermissions = array_diff(
        ['employees.read', 'shifts.read'],
        $managerRole->permissions()->pluck('name')->all(),
    );

    if ($missingManagerPermissions !== []) {
        $managerRole->givePermissionTo(array_values($missingManagerPermissions));
    }
}

function grantUserPermissionManagementAccess(User $user, int $tenantId): void
{
    foreach (['permissions.read', 'permissions.assign_direct', 'permissions.revoke_direct'] as $permissionName) {
        givePermissionWithTenant($user, $tenantId, $permissionName);
    }
}

function assignRoleWithTenant(User $user, int $tenantId, string $role): void
{
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenantId);
    $user->assignRole($role);
    $registrar->setPermissionsTeamId(null);
    $registrar->forgetCachedPermissions();
}

function createUserPermissionAssignmentContext(): array
{
    $keys = TenantKey::generateEnvelopeKeys();
    $tenant = TenantKey::create($keys);

    /** @var PermissionRegistrar $registrar */
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    seedUserPermissionAssignmentPermissions();
    seedUserPermissionAssignmentRoles($tenant);

    $otherTenantKeys = TenantKey::generateEnvelopeKeys();
    $otherTenant = TenantKey::create($otherTenantKeys);
    $crossTenantUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

    return [
        'tenant' => $tenant,
        'registrar' => $registrar,
        'crossTenantUser' => $crossTenantUser,
    ];
}

function createTenantUser(TenantKey $tenant): User
{
    return User::factory()->create(['tenant_id' => $tenant->id]);
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

test('user permission assignment bootstrap tolerates pre-seeded permissions and roles', function (): void {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    expect(function () use ($tenant): void {
        seedUserPermissionAssignmentPermissions();
        seedUserPermissionAssignmentRoles($tenant);
    })->not->toThrow(Exception::class);

    expect(Permission::query()
        ->where('guard_name', 'sanctum')
        ->whereIn('name', [
            'employees.read',
            'employees.export',
            'reports.generate',
            'shifts.read',
            'permissions.read',
            'permissions.assign_direct',
            'permissions.revoke_direct',
        ])
        ->count())->toBe(7);

    expect(Role::query()
        ->where('guard_name', 'sanctum')
        ->where('tenant_id', $tenant->id)
        ->whereIn('name', ['Manager'])
        ->count())->toBe(1);
});

test('user can view own permissions via_roles and direct and all', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $user = createTenantUser($tenant);
    assignRoleWithTenant($user, $tenant->id, 'Manager');
    givePermissionWithTenant($user, $tenant->id, 'employees.export');

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

test('privileged user can view any user permissions', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    $targetUser = createTenantUser($tenant);
    assignRoleWithTenant($targetUser, $tenant->id, 'Manager');

    actingAs($admin, 'sanctum');

    $response = getJson("/v1/users/{$targetUser->id}/permissions");

    $response->assertOk()
        ->assertJsonStructure(['data' => ['via_roles', 'direct', 'all']]);
});

test('privileged user gets 404 when viewing cross-tenant user permissions', function () {
    ['tenant' => $tenant, 'crossTenantUser' => $crossTenantUser] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    actingAs($admin, 'sanctum');

    $response = getJson("/v1/users/{$crossTenantUser->id}/permissions");

    $response->assertNotFound();
});

test('user cannot view other user permissions', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $user = createTenantUser($tenant);
    $otherUser = createTenantUser($tenant);

    actingAs($user, 'sanctum');

    $response = getJson("/v1/users/{$otherUser->id}/permissions");

    $response->assertForbidden();
});

test('privileged user can assign direct permission to user', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    $targetUser = createTenantUser($tenant);

    actingAs($admin, 'sanctum');

    $response = postJson("/v1/users/{$targetUser->id}/permissions", [
        'permissions' => ['employees.export'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.0.name', 'employees.export');

    expect($targetUser->fresh()->hasDirectPermission('employees.export'))->toBeTrue();
});

test('hasDirectPermission ignores assignments from another tenant context', function () {
    ['tenant' => $tenant, 'registrar' => $registrar] = createUserPermissionAssignmentContext();

    $user = createTenantUser($tenant);

    $otherTenantKeys = TenantKey::generateEnvelopeKeys();
    $otherTenant = TenantKey::create($otherTenantKeys);

    givePermissionWithTenant($user, $otherTenant->id, 'employees.export');

    $registrar->setPermissionsTeamId($tenant->id);

    expect($user->fresh()->hasDirectPermission('employees.export'))->toBeFalse();
});

test('hasDirectPermission ignores expired direct assignments', function () {
    ['tenant' => $tenant, 'registrar' => $registrar] = createUserPermissionAssignmentContext();

    $user = createTenantUser($tenant);
    $permission = Permission::findByName('employees.export', 'sanctum');

    $registrar->setPermissionsTeamId($tenant->id);

    DB::table('model_has_permissions')->insert([
        'permission_id' => $permission->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'tenant_id' => $tenant->id,
        'valid_from' => now()->subDays(2),
        'valid_until' => now()->subDay(),
        'assigned_by' => null,
        'reason' => 'expired test permission',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($user->fresh()->hasDirectPermission('employees.export'))->toBeFalse();
});

test('privileged user gets 404 when assigning permission to cross-tenant user', function () {
    ['tenant' => $tenant, 'crossTenantUser' => $crossTenantUser] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    actingAs($admin, 'sanctum');

    $response = postJson("/v1/users/{$crossTenantUser->id}/permissions", [
        'permissions' => ['employees.export'],
    ]);

    $response->assertNotFound()
        ->assertExactJson([
            'message' => 'Resource not found.',
        ]);
});

test('privileged user can assign direct permission with temporal constraints', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    $targetUser = createTenantUser($tenant);

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

test('user without permission-management access cannot assign permissions', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $user = createTenantUser($tenant);
    $targetUser = createTenantUser($tenant);

    actingAs($user, 'sanctum');

    $response = postJson("/v1/users/{$targetUser->id}/permissions", [
        'permissions' => ['employees.export'],
    ]);

    $response->assertForbidden();
});

test('privileged user can revoke direct permission from user', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    $targetUser = createTenantUser($tenant);
    givePermissionWithTenant($targetUser, $tenant->id, 'employees.export');

    expect($targetUser->hasDirectPermission('employees.export'))->toBeTrue();

    actingAs($admin, 'sanctum');

    $response = deleteJson("/v1/users/{$targetUser->id}/permissions/employees.export");

    $response->assertOk();

    expect($targetUser->fresh()->hasDirectPermission('employees.export'))->toBeFalse();
});

test('privileged user gets 404 when revoking permission from cross-tenant user', function () {
    ['tenant' => $tenant, 'crossTenantUser' => $crossTenantUser] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    actingAs($admin, 'sanctum');

    $response = deleteJson("/v1/users/{$crossTenantUser->id}/permissions/employees.export");

    $response->assertNotFound();
});

test('revoking direct permission does not affect role permissions', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    $targetUser = createTenantUser($tenant);
    assignRoleWithTenant($targetUser, $tenant->id, 'Manager');
    givePermissionWithTenant($targetUser, $tenant->id, 'employees.read');

    actingAs($admin, 'sanctum');

    $response = deleteJson("/v1/users/{$targetUser->id}/permissions/employees.read");

    $response->assertOk();

    expect($targetUser->fresh()->hasDirectPermission('employees.read'))->toBeFalse()
        ->and($targetUser->hasPermissionTo('employees.read'))->toBeTrue();
});

test('user can view only direct permissions', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $user = createTenantUser($tenant);
    assignRoleWithTenant($user, $tenant->id, 'Manager');
    givePermissionWithTenant($user, $tenant->id, 'employees.export');

    actingAs($user, 'sanctum');

    $response = getJson("/v1/users/{$user->id}/permissions/direct");

    $response->assertOk()
        ->assertJsonCount(1, 'data.direct')
        ->assertJsonPath('data.direct.0.name', 'employees.export');
});

test('privileged user gets 404 when viewing direct permissions for cross-tenant user', function () {
    ['tenant' => $tenant, 'crossTenantUser' => $crossTenantUser] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    actingAs($admin, 'sanctum');

    $response = getJson("/v1/users/{$crossTenantUser->id}/permissions/direct");

    $response->assertNotFound();
});

test('unauthenticated user cannot access permissions endpoints', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $user = createTenantUser($tenant);

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

test('global permission catalog endpoints are not exposed at runtime', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    actingAs($admin, 'sanctum');

    getJson('/v1/permissions')
        ->assertNotFound()
        ->assertExactJson([
            'message' => 'Resource not found.',
        ]);

    getJson('/v1/permissions/1')
        ->assertNotFound()
        ->assertExactJson([
            'message' => 'Resource not found.',
        ]);
});

test('validation fails when permissions array is empty', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    $targetUser = createTenantUser($tenant);

    actingAs($admin, 'sanctum');

    $response = postJson("/v1/users/{$targetUser->id}/permissions", [
        'permissions' => [],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions']);
});

test('validation fails when permission does not exist', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    $targetUser = createTenantUser($tenant);

    actingAs($admin, 'sanctum');

    $response = postJson("/v1/users/{$targetUser->id}/permissions", [
        'permissions' => ['nonexistent.permission'],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions.0']);
});

test('validation fails when valid_until is before valid_from', function () {
    ['tenant' => $tenant] = createUserPermissionAssignmentContext();

    $admin = createTenantUser($tenant);
    grantUserPermissionManagementAccess($admin, $tenant->id);

    $targetUser = createTenantUser($tenant);

    actingAs($admin, 'sanctum');

    $response = postJson("/v1/users/{$targetUser->id}/permissions", [
        'permissions' => ['employees.export'],
        'valid_from' => now()->addDays(7)->toIso8601String(),
        'valid_until' => now()->toIso8601String(),
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['valid_until']);
});
