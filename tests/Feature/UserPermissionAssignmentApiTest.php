<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    // Create permissions for testing
    Permission::create(['name' => 'employees.read', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'employees.export', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'reports.generate', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'shifts.read', 'guard_name' => 'sanctum']);

    // Create roles with permissions
    $managerRole = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);
    $managerRole->givePermissionTo(['employees.read', 'shifts.read']);

    $adminRole = Role::create(['name' => 'Admin', 'guard_name' => 'sanctum']);
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('user can view own permissions via_roles and direct and all', function () {
    $user = User::factory()->create();
    $user->assignRole('Manager');
    $user->givePermissionTo('employees.export'); // Direct permission

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/users/{$user->id}/permissions");

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
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('Manager');

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/users/{$targetUser->id}/permissions");

    $response->assertOk()
        ->assertJsonStructure(['data' => ['via_roles', 'direct', 'all']]);
});

test('user cannot view other user permissions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/users/{$otherUser->id}/permissions");

    $response->assertForbidden();
});

test('admin can assign direct permission to user', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/users/{$targetUser->id}/permissions", [
            'permissions' => ['employees.export'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.0.name', 'employees.export');

    expect($targetUser->fresh()->hasDirectPermission('employees.export'))->toBeTrue();
});

test('admin can assign direct permission with temporal constraints', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();

    $validFrom = now()->toIso8601String();
    $validUntil = now()->addDays(7)->toIso8601String();

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/users/{$targetUser->id}/permissions", [
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
    $user = User::factory()->create();
    $targetUser = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/users/{$targetUser->id}/permissions", [
            'permissions' => ['employees.export'],
        ]);

    $response->assertForbidden();
});

test('admin can revoke direct permission from user', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();
    $targetUser->givePermissionTo('employees.export');

    expect($targetUser->hasDirectPermission('employees.export'))->toBeTrue();

    $response = $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/v1/users/{$targetUser->id}/permissions/employees.export");

    $response->assertOk();

    expect($targetUser->fresh()->hasDirectPermission('employees.export'))->toBeFalse();
});

test('revoking direct permission does not affect role permissions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('Manager'); // Has employees.read via role
    $targetUser->givePermissionTo('employees.read'); // Also has it directly

    $response = $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/v1/users/{$targetUser->id}/permissions/employees.read");

    $response->assertOk();

    // Direct permission removed, but still has via role
    expect($targetUser->fresh()->hasDirectPermission('employees.read'))->toBeFalse()
        ->and($targetUser->hasPermissionTo('employees.read'))->toBeTrue(); // Still has via role
});

test('user can view only direct permissions', function () {
    $user = User::factory()->create();
    $user->assignRole('Manager'); // Has employees.read, shifts.read via role
    $user->givePermissionTo('employees.export'); // Direct

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/users/{$user->id}/permissions/direct");

    $response->assertOk()
        ->assertJsonCount(1, 'data.direct')
        ->assertJsonPath('data.direct.0.name', 'employees.export');
});

test('unauthenticated user cannot access permissions endpoints', function () {
    $user = User::factory()->create();

    $this->getJson("/api/v1/users/{$user->id}/permissions")
        ->assertUnauthorized();

    $this->postJson("/api/v1/users/{$user->id}/permissions", [
        'permissions' => ['employees.read'],
    ])->assertUnauthorized();

    $this->deleteJson("/api/v1/users/{$user->id}/permissions/employees.read")
        ->assertUnauthorized();

    $this->getJson("/api/v1/users/{$user->id}/permissions/direct")
        ->assertUnauthorized();
});

test('validation fails when permissions array is empty', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/users/{$targetUser->id}/permissions", [
            'permissions' => [],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions']);
});

test('validation fails when permission does not exist', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/users/{$targetUser->id}/permissions", [
            'permissions' => ['nonexistent.permission'],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions.0']);
});

test('validation fails when valid_until is before valid_from', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $targetUser = User::factory()->create();

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/users/{$targetUser->id}/permissions", [
            'permissions' => ['employees.export'],
            'valid_from' => now()->addDays(7)->toIso8601String(),
            'valid_until' => now()->toIso8601String(),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['valid_until']);
});
