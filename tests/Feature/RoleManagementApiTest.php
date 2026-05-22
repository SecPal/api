<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Http\Requests\Api\V1\CreateRoleRequest;
use App\Http\Requests\Api\V1\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property User $user
 * @property mixed $token
 */
uses(RefreshDatabase::class);

function seedRoleManagementPermissions(): void
{
    foreach ([
        'roles.read',
        'roles.create',
        'roles.update',
        'roles.delete',
        'employees.read',
        'employees.create',
        'shifts.read',
    ] as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'sanctum']);
    }
}

function createRoleManagementRole(string $name, string $guardName = 'sanctum'): Role
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

function createRoleManagementRoleInTenant(TenantKey $tenant, string $name, string $guardName = 'sanctum'): Role
{
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();

    $registrar->setPermissionsTeamId($tenant->id);
    $role = createRoleManagementRole($name, $guardName);
    $registrar->setPermissionsTeamId($previousTeamId);
    $registrar->forgetCachedPermissions();

    return $role;
}

function resetRoleManagementRbacState(): void
{
    DB::table('role_has_permissions')->delete();
    DB::table('model_has_roles')->delete();
    DB::table('model_has_permissions')->delete();
    Role::query()->delete();
    Permission::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

beforeEach(function (): void {
    // Use process-specific KEK file for parallel test isolation
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);
    resetRoleManagementRbacState();

    // Create test user with token
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    seedRoleManagementPermissions();
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('role management permission bootstrap', function () {
    test('tolerates pre-seeded permissions', function (): void {
        expect(fn (): mixed => seedRoleManagementPermissions())->not->toThrow(Exception::class);

        expect(Permission::query()
            ->where('guard_name', 'sanctum')
            ->whereIn('name', [
                'roles.read',
                'roles.create',
                'roles.update',
                'roles.delete',
                'employees.read',
                'employees.create',
                'shifts.read',
            ])
            ->count())->toBe(7);
    });

    test('tolerates pre-seeded role fixtures', function (): void {
        createRoleManagementRole('Manager');
        createRoleManagementRole('Guard');

        expect(fn (): Role => createRoleManagementRole('Manager'))->not->toThrow(Exception::class);
        expect(fn (): Role => createRoleManagementRole('Guard'))->not->toThrow(Exception::class);

        expect(Role::query()
            ->where('guard_name', 'sanctum')
            ->whereIn('name', ['Manager', 'Guard'])
            ->where('tenant_id', $this->tenant->id)
            ->count())->toBe(2);
    });
});

describe('GET /v1/roles - List Roles', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/roles');

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks roles.read permission', function (): void {
        // User has no permissions

        $response = $this->withToken($this->token)
            ->getJson('/v1/roles');

        $response->assertForbidden();
    });

    test('returns empty list when no roles exist', function (): void {
        $this->user->givePermissionTo('roles.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/roles');

        $response->assertOk()
            ->assertJsonStructure(['data'])
            ->assertJson(['data' => []]);
    });

    test('returns list of all roles with permission count', function (): void {
        $this->user->givePermissionTo('roles.read');

        // Create test roles
        $manager = createRoleManagementRole('Manager');
        $guard = createRoleManagementRole('Guard');

        // Assign permissions
        $manager->givePermissionTo(['employees.read', 'employees.create', 'shifts.read']);
        $guard->givePermissionTo('shifts.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/roles');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'permissions_count', 'users_count', 'created_at', 'updated_at'],
                ],
            ])
            ->assertJsonFragment(['name' => 'Manager', 'permissions_count' => 3])
            ->assertJsonFragment(['name' => 'Guard', 'permissions_count' => 1]);
    });

    test('does not list roles from another tenant', function (): void {
        $this->user->givePermissionTo('roles.read');

        createRoleManagementRole('Manager');
        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        createRoleManagementRoleInTenant($otherTenant, 'Other Tenant Manager');

        $response = $this->withToken($this->token)
            ->getJson('/v1/roles');

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Manager'])
            ->assertJsonMissing(['name' => 'Other Tenant Manager']);
    });
});

describe('POST /v1/roles - Create Role', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson('/v1/roles', [
            'name' => 'Regional Manager',
            'permissions' => ['employees.read'],
        ]);

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks roles.create permission', function (): void {
        $this->user->givePermissionTo('roles.read');

        $response = $this->withToken($this->token)
            ->postJson('/v1/roles', [
                'name' => 'Regional Manager',
                'permissions' => ['employees.read'],
            ]);

        $response->assertForbidden();
    });

    test('returns 422 when name is missing', function (): void {
        $this->user->givePermissionTo('roles.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/roles', [
                'permissions' => ['employees.read'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('returns 422 when name already exists', function (): void {
        $this->user->givePermissionTo('roles.create');
        createRoleManagementRole('Manager');

        $response = $this->withToken($this->token)
            ->postJson('/v1/roles', [
                'name' => 'Manager',
                'permissions' => ['employees.read'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('ignores spoofed tenant input when validating role creation uniqueness', function (): void {
        $manager = createRoleManagementRole('Manager');
        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());

        $this->registrar->setPermissionsTeamId($otherTenant->id);
        $otherTenantManager = createRoleManagementRole('Manager');
        $this->registrar->setPermissionsTeamId($this->tenant->id);
        $this->registrar->forgetCachedPermissions();

        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->where('tenant_id', $manager->tenant_id)
            ->exists())->toBeTrue();
        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->where('tenant_id', $otherTenantManager->tenant_id)
            ->exists())->toBeTrue();
        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->whereIn('tenant_id', [$manager->tenant_id, $otherTenantManager->tenant_id])
            ->count())->toBe(2);

        $request = CreateRoleRequest::create('/v1/roles', 'POST', [
            'name' => 'Manager',
            'permissions' => ['employees.read'],
            'tenant_id' => $otherTenant->id,
        ]);
        $request->setContainer(app());
        $request->setUserResolver(fn (): User => $this->user);

        $validator = validator($request->all(), $request->rules(), $request->messages());

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->keys())->toContain('name');
    });

    test('allows creating a sanctum role when the same name exists only under another guard', function (): void {
        $this->user->givePermissionTo('roles.create');
        createRoleManagementRole('Manager', 'web');

        $response = $this->withToken($this->token)
            ->postJson('/v1/roles', [
                'name' => 'Manager',
                'permissions' => ['employees.read'],
            ]);

        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Manager']);

        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->where('tenant_id', $this->tenant->id)
            ->exists())->toBeTrue();
    });

    test('allows creating a role when the same name exists in another tenant', function (): void {
        $this->user->givePermissionTo('roles.create');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());

        $this->registrar->setPermissionsTeamId($otherTenant->id);
        createRoleManagementRole('Manager');
        $this->registrar->setPermissionsTeamId($this->tenant->id);
        $this->registrar->forgetCachedPermissions();

        $response = $this->withToken($this->token)
            ->postJson('/v1/roles', [
                'name' => 'Manager',
                'permissions' => ['employees.read'],
            ]);

        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Manager']);

        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->where('tenant_id', $otherTenant->id)
            ->exists())->toBeTrue();

        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->where('tenant_id', $this->tenant->id)
            ->exists())->toBeTrue();

        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->whereIn('tenant_id', [$otherTenant->id, $this->tenant->id])
            ->count())->toBe(2);
    });

    test('returns 422 when permissions array contains non-existent permission', function (): void {
        $this->user->givePermissionTo('roles.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/roles', [
                'name' => 'Regional Manager',
                'permissions' => ['non.existent.permission'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);
    });

    test('creates role with permissions successfully', function (): void {
        $this->user->givePermissionTo('roles.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/roles', [
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
            ->postJson('/v1/roles', [
                'name' => 'Empty Role',
                'permissions' => [],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.permissions', []);
    });
});

describe('GET /v1/roles/{id} - Get Role Details', function () {
    test('returns 401 when not authenticated', function (): void {
        $role = createRoleManagementRole('Manager');

        $response = $this->getJson("/v1/roles/{$role->id}");

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks roles.read permission', function (): void {
        $role = createRoleManagementRole('Manager');

        $response = $this->withToken($this->token)
            ->getJson("/v1/roles/{$role->id}");

        $response->assertForbidden();
    });

    test('returns 404 when role does not exist', function (): void {
        $this->user->givePermissionTo('roles.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/roles/999999');

        $response->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
            ]);
    });

    test('returns role details with permissions', function (): void {
        $this->user->givePermissionTo('roles.read');

        $role = createRoleManagementRole('Manager');
        $role->givePermissionTo(['employees.read', 'shifts.read']);

        $response = $this->withToken($this->token)
            ->getJson("/v1/roles/{$role->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'permissions', 'users_count', 'created_at', 'updated_at'],
            ])
            ->assertJsonFragment(['name' => 'Manager'])
            ->assertJsonPath('data.permissions', fn ($permissions) => count($permissions) === 2);
    });

    test('returns 404 when accessing a role from another tenant', function (): void {
        $this->user->givePermissionTo('roles.read');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $foreignRole = createRoleManagementRoleInTenant($otherTenant, 'Other Tenant Manager');

        $response = $this->withToken($this->token)
            ->getJson("/v1/roles/{$foreignRole->id}");

        $response->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
            ]);
    });
});

describe('PATCH /v1/roles/{id} - Update Role', function () {
    test('returns 401 when not authenticated', function (): void {
        $role = createRoleManagementRole('Manager');

        $response = $this->patchJson("/v1/roles/{$role->id}", [
            'name' => 'Senior Manager',
        ]);

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks roles.update permission', function (): void {
        $this->user->givePermissionTo('roles.read');
        $role = createRoleManagementRole('Manager');

        $response = $this->withToken($this->token)
            ->patchJson("/v1/roles/{$role->id}", [
                'name' => 'Senior Manager',
            ]);

        $response->assertForbidden();
    });

    test('returns 404 when role does not exist', function (): void {
        $this->user->givePermissionTo('roles.update');

        $response = $this->withToken($this->token)
            ->patchJson('/v1/roles/999999', [
                'name' => 'New Name',
            ]);

        $response->assertNotFound();
    });

    test('returns 422 when name already exists for another role', function (): void {
        $this->user->givePermissionTo('roles.update');
        createRoleManagementRole('Manager');
        $guard = createRoleManagementRole('Guard');

        $response = $this->withToken($this->token)
            ->patchJson("/v1/roles/{$guard->id}", [
                'name' => 'Manager',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('ignores spoofed tenant input when validating role updates', function (): void {
        $manager = createRoleManagementRole('Manager');
        createRoleManagementRole('Guard');
        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());

        $this->registrar->setPermissionsTeamId($otherTenant->id);
        $otherTenantManager = createRoleManagementRole('Manager');
        $this->registrar->setPermissionsTeamId($this->tenant->id);
        $this->registrar->forgetCachedPermissions();

        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->where('tenant_id', $manager->tenant_id)
            ->exists())->toBeTrue();
        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->where('tenant_id', $otherTenantManager->tenant_id)
            ->exists())->toBeTrue();
        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->whereIn('tenant_id', [$manager->tenant_id, $otherTenantManager->tenant_id])
            ->count())->toBe(2);

        $request = UpdateRoleRequest::create("/v1/roles/{$manager->id}", 'PATCH', [
            'name' => 'Manager',
            'tenant_id' => $otherTenant->id,
        ]);
        $request->setContainer(app());
        $request->setUserResolver(fn (): User => $this->user);

        $validator = validator($request->all(), $request->rules(), $request->messages());

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->keys())->toContain('name');
    });

    test('allows updating a sanctum role when the target name exists only under another guard', function (): void {
        $this->user->givePermissionTo('roles.update');
        createRoleManagementRole('Manager', 'web');
        $role = createRoleManagementRole('Guard');

        $response = $this->withToken($this->token)
            ->patchJson("/v1/roles/{$role->id}", [
                'name' => 'Manager',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Manager']);

        expect($role->fresh()?->name)->toBe('Manager');
    });

    test('allows updating a role when the target name exists in another tenant', function (): void {
        $this->user->givePermissionTo('roles.update');
        $role = createRoleManagementRole('Guard');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());

        $this->registrar->setPermissionsTeamId($otherTenant->id);
        createRoleManagementRole('Manager');
        $this->registrar->setPermissionsTeamId($this->tenant->id);
        $this->registrar->forgetCachedPermissions();

        $response = $this->withToken($this->token)
            ->patchJson("/v1/roles/{$role->id}", [
                'name' => 'Manager',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Manager']);

        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->where('tenant_id', $otherTenant->id)
            ->exists())->toBeTrue();

        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->where('tenant_id', $this->tenant->id)
            ->exists())->toBeTrue();

        expect(Role::query()
            ->where('name', 'Manager')
            ->where('guard_name', 'sanctum')
            ->whereIn('tenant_id', [$otherTenant->id, $this->tenant->id])
            ->count())->toBe(2);
    });

    test('updates role name successfully', function (): void {
        $this->user->givePermissionTo('roles.update');
        $role = createRoleManagementRole('Manager');

        $response = $this->withToken($this->token)
            ->patchJson("/v1/roles/{$role->id}", [
                'name' => 'Senior Manager',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Senior Manager']);

        expect($role->fresh()->name)->toBe('Senior Manager');
    });

    test('updates role permissions successfully', function (): void {
        $this->user->givePermissionTo('roles.update');
        $role = createRoleManagementRole('Manager');
        $role->givePermissionTo('employees.read');

        $response = $this->withToken($this->token)
            ->patchJson("/v1/roles/{$role->id}", [
                'permissions' => ['shifts.read', 'employees.create'],
            ]);

        $response->assertOk();

        $permissions = $role->fresh()->permissions->pluck('name')->toArray();
        expect($permissions)
            ->toContain('shifts.read')
            ->toContain('employees.create')
            ->toHaveCount(2);
    });

    test('returns 404 when updating a role from another tenant', function (): void {
        $this->user->givePermissionTo('roles.update');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $foreignRole = createRoleManagementRoleInTenant($otherTenant, 'Other Tenant Manager');

        $response = $this->withToken($this->token)
            ->patchJson("/v1/roles/{$foreignRole->id}", [
                'name' => 'Compromised Name',
            ]);

        $response->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
            ]);

        expect($foreignRole->fresh()?->name)->toBe('Other Tenant Manager');
    });

    test('returns 422 when permissions is explicitly null', function (): void {
        $this->user->givePermissionTo('roles.update');
        $role = createRoleManagementRole('Manager');
        $role->givePermissionTo('employees.read');

        $response = $this->withToken($this->token)
            ->patchJson("/v1/roles/{$role->id}", [
                'permissions' => null,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions']);

        // Permissions must not be cleared by a null payload.
        expect($role->fresh()->permissions->pluck('name'))->toContain('employees.read');
    });
});

describe('DELETE /v1/roles/{id} - Delete Role', function () {
    test('returns 401 when not authenticated', function (): void {
        $role = createRoleManagementRole('Manager');

        $response = $this->deleteJson("/v1/roles/{$role->id}");

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks roles.delete permission', function (): void {
        $this->user->givePermissionTo('roles.read');
        $role = createRoleManagementRole('Manager');

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/roles/{$role->id}");

        $response->assertForbidden();
    });

    test('returns 404 when role does not exist', function (): void {
        $this->user->givePermissionTo('roles.delete');

        $response = $this->withToken($this->token)
            ->deleteJson('/v1/roles/999999');

        $response->assertNotFound();
    });

    test('returns 422 when role is assigned to users', function (): void {
        $this->user->givePermissionTo('roles.delete');
        $role = createRoleManagementRole('Manager');

        // Assign role to a user
        $otherUser = User::factory()->create();
        $otherUser->assignRole($role);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/roles/{$role->id}");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot delete role while assigned to users'])
            ->assertJsonFragment(['assigned_to' => 1]);
    });

    test('deletes role successfully when not assigned', function (): void {
        $this->user->givePermissionTo('roles.delete');
        $role = createRoleManagementRole('Manager');

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/roles/{$role->id}");

        $response->assertNoContent();

        expect(Role::where('id', $role->id)->exists())->toBeFalse();
    });

    test('returns 404 when deleting a role from another tenant', function (): void {
        $this->user->givePermissionTo('roles.delete');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $foreignRole = createRoleManagementRoleInTenant($otherTenant, 'Other Tenant Manager');

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/roles/{$foreignRole->id}");

        $response->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
            ]);

        expect(Role::whereKey($foreignRole->id)->exists())->toBeTrue();
    });
});
