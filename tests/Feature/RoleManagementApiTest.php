<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
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

    // Create test user with token
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    // Create permissions for role management
    Permission::create(['name' => 'roles.read', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'roles.create', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'roles.update', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'roles.delete', 'guard_name' => 'sanctum']);

    // Create test permissions for assignment
    Permission::create(['name' => 'employees.read', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'employees.create', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'shifts.read', 'guard_name' => 'sanctum']);
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/roles - List Roles', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/api/v1/roles');

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks roles.read permission', function (): void {
        // User has no permissions

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/roles');

        $response->assertForbidden();
    });

    test('returns empty list when no roles exist', function (): void {
        $this->user->givePermissionTo('roles.read');

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/roles');

        $response->assertOk()
            ->assertJsonStructure(['data'])
            ->assertJson(['data' => []]);
    });

    test('returns list of all roles with permission count', function (): void {
        $this->user->givePermissionTo('roles.read');

        // Create test roles
        $manager = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);
        $guard = Role::create(['name' => 'Guard', 'guard_name' => 'sanctum']);

        // Assign permissions
        $manager->givePermissionTo(['employees.read', 'employees.create', 'shifts.read']);
        $guard->givePermissionTo('shifts.read');

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/roles');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'permissions_count', 'users_count', 'created_at', 'updated_at'],
                ],
            ])
            ->assertJsonFragment(['name' => 'Manager', 'permissions_count' => 3])
            ->assertJsonFragment(['name' => 'Guard', 'permissions_count' => 1]);
    });
});

describe('POST /v1/roles - Create Role', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Regional Manager',
            'permissions' => ['employees.read'],
        ]);

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks roles.create permission', function (): void {
        $this->user->givePermissionTo('roles.read');

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/roles', [
                'name' => 'Regional Manager',
                'permissions' => ['employees.read'],
            ]);

        $response->assertForbidden();
    });

    test('returns 422 when name is missing', function (): void {
        $this->user->givePermissionTo('roles.create');

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/roles', [
                'permissions' => ['employees.read'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('returns 422 when name already exists', function (): void {
        $this->user->givePermissionTo('roles.create');
        Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/roles', [
                'name' => 'Manager',
                'permissions' => ['employees.read'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('returns 422 when permissions array contains non-existent permission', function (): void {
        $this->user->givePermissionTo('roles.create');

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/roles', [
                'name' => 'Regional Manager',
                'permissions' => ['non.existent.permission'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);
    });

    test('creates role with permissions successfully', function (): void {
        $this->user->givePermissionTo('roles.create');

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/roles', [
                'name' => 'Regional Manager',
                'permissions' => ['employees.read', 'shifts.read'],
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'permissions', 'created_at', 'updated_at'],
            ])
            ->assertJsonFragment(['name' => 'Regional Manager'])
            ->assertJsonPath('data.permissions', fn ($permissions) => count($permissions) === 2);

        expect(Role::where('name', 'Regional Manager')->exists())->toBeTrue();
    });

    test('creates role without permissions when permissions array is empty', function (): void {
        $this->user->givePermissionTo('roles.create');

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/roles', [
                'name' => 'Empty Role',
                'permissions' => [],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.permissions', []);
    });
});

describe('GET /v1/roles/{id} - Get Role Details', function () {
    test('returns 401 when not authenticated', function (): void {
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);

        $response = $this->getJson("/api/v1/roles/{$role->id}");

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks roles.read permission', function (): void {
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/roles/{$role->id}");

        $response->assertForbidden();
    });

    test('returns 404 when role does not exist', function (): void {
        $this->user->givePermissionTo('roles.read');

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/roles/999999');

        $response->assertNotFound();
    });

    test('returns role details with permissions', function (): void {
        $this->user->givePermissionTo('roles.read');

        $role = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);
        $role->givePermissionTo(['employees.read', 'shifts.read']);

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/roles/{$role->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'permissions', 'users_count', 'created_at', 'updated_at'],
            ])
            ->assertJsonFragment(['name' => 'Manager'])
            ->assertJsonPath('data.permissions', fn ($permissions) => count($permissions) === 2);
    });
});

describe('PATCH /v1/roles/{id} - Update Role', function () {
    test('returns 401 when not authenticated', function (): void {
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);

        $response = $this->patchJson("/api/v1/roles/{$role->id}", [
            'name' => 'Senior Manager',
        ]);

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks roles.update permission', function (): void {
        $this->user->givePermissionTo('roles.read');
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);

        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/roles/{$role->id}", [
                'name' => 'Senior Manager',
            ]);

        $response->assertForbidden();
    });

    test('returns 404 when role does not exist', function (): void {
        $this->user->givePermissionTo('roles.update');

        $response = $this->withToken($this->token)
            ->patchJson('/api/v1/roles/999999', [
                'name' => 'New Name',
            ]);

        $response->assertNotFound();
    });

    test('returns 422 when name already exists for another role', function (): void {
        $this->user->givePermissionTo('roles.update');
        Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);
        $guard = Role::create(['name' => 'Guard', 'guard_name' => 'sanctum']);

        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/roles/{$guard->id}", [
                'name' => 'Manager',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('updates role name successfully', function (): void {
        $this->user->givePermissionTo('roles.update');
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);

        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/roles/{$role->id}", [
                'name' => 'Senior Manager',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Senior Manager']);

        expect($role->fresh()->name)->toBe('Senior Manager');
    });

    test('updates role permissions successfully', function (): void {
        $this->user->givePermissionTo('roles.update');
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);
        $role->givePermissionTo('employees.read');

        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/roles/{$role->id}", [
                'permissions' => ['shifts.read', 'employees.create'],
            ]);

        $response->assertOk();

        $permissions = $role->fresh()->permissions->pluck('name')->toArray();
        expect($permissions)
            ->toContain('shifts.read')
            ->toContain('employees.create')
            ->toHaveCount(2);
    });
});

describe('DELETE /v1/roles/{id} - Delete Role', function () {
    test('returns 401 when not authenticated', function (): void {
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);

        $response = $this->deleteJson("/api/v1/roles/{$role->id}");

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks roles.delete permission', function (): void {
        $this->user->givePermissionTo('roles.read');
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);

        $response = $this->withToken($this->token)
            ->deleteJson("/api/v1/roles/{$role->id}");

        $response->assertForbidden();
    });

    test('returns 404 when role does not exist', function (): void {
        $this->user->givePermissionTo('roles.delete');

        $response = $this->withToken($this->token)
            ->deleteJson('/api/v1/roles/999999');

        $response->assertNotFound();
    });

    test('returns 422 when role is assigned to users', function (): void {
        $this->user->givePermissionTo('roles.delete');
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);

        // Assign role to a user
        $otherUser = User::factory()->create();
        $otherUser->assignRole($role);

        $response = $this->withToken($this->token)
            ->deleteJson("/api/v1/roles/{$role->id}");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot delete role while assigned to users'])
            ->assertJsonFragment(['assigned_to' => 1]);
    });

    test('deletes role successfully when not assigned', function (): void {
        $this->user->givePermissionTo('roles.delete');
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);

        $response = $this->withToken($this->token)
            ->deleteJson("/api/v1/roles/{$role->id}");

        $response->assertNoContent();

        expect(Role::where('id', $role->id)->exists())->toBeFalse();
    });
});
