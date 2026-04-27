<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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

function seedPermissionManagementPermissions(): void
{
    foreach ([
        'permissions.read',
        'permissions.create',
        'permissions.update',
        'permissions.delete',
        'employees.read',
        'employees.create',
        'shifts.read',
        'shifts.publish',
    ] as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'sanctum']);
    }
}

function createPermissionManagementRole(string $name): Role
{
    $teamForeignKey = config('permission.column_names.team_foreign_key');
    $teamId = app(PermissionRegistrar::class)->getPermissionsTeamId();

    $attributes = [
        'name' => $name,
        'guard_name' => 'sanctum',
    ];

    if (is_string($teamForeignKey) && $teamForeignKey !== '' && $teamId !== null) {
        $attributes[$teamForeignKey] = $teamId;
    }

    $role = Role::firstOrCreate($attributes);
    $role->syncPermissions([]);

    return $role;
}

function createPermissionManagementPermission(string $name): Permission
{
    return Permission::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);
}

function resetPermissionManagementRbacState(): void
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
    resetPermissionManagementRbacState();

    // Create test user with token
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    seedPermissionManagementPermissions();
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/permissions - List Permissions', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/permissions');

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks permissions.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson('/v1/permissions');

        $response->assertForbidden();
    });

    test('lists permissions grouped by resource', function (): void {
        $this->user->givePermissionTo('permissions.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/permissions');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'employees' => [
                        '*' => ['id', 'name', 'roles_count', 'created_at', 'updated_at'],
                    ],
                    'shifts' => [
                        '*' => ['id', 'name', 'roles_count', 'created_at', 'updated_at'],
                    ],
                    'permissions' => [
                        '*' => ['id', 'name', 'roles_count', 'created_at', 'updated_at'],
                    ],
                ],
            ]);

        // Verify grouping
        $data = $response->json('data');
        expect($data)->toHaveKeys(['employees', 'shifts', 'permissions']);
        expect($data['employees'])->toHaveCount(2); // employees.read, employees.create
        expect($data['shifts'])->toHaveCount(2); // shifts.read, shifts.publish
    });
});

describe('POST /v1/permissions - Create Permission', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson('/v1/permissions', [
            'name' => 'reports.generate',
        ]);

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks permissions.create permission', function (): void {
        $this->user->givePermissionTo('permissions.read');

        $response = $this->withToken($this->token)
            ->postJson('/v1/permissions', [
                'name' => 'reports.generate',
            ]);

        $response->assertForbidden();
    });

    test('returns 422 when name is missing', function (): void {
        $this->user->givePermissionTo('permissions.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/permissions', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('returns 422 when name format is invalid', function (): void {
        $this->user->givePermissionTo('permissions.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/permissions', [
                'name' => 'invalid_format',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('returns 422 when name already exists', function (): void {
        $this->user->givePermissionTo('permissions.create');
        createPermissionManagementPermission('reports.generate');

        $response = $this->withToken($this->token)
            ->postJson('/v1/permissions', [
                'name' => 'reports.generate',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('creates permission successfully', function (): void {
        $this->user->givePermissionTo('permissions.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/permissions', [
                'name' => 'reports.generate',
                'description' => 'Generate reports',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'description', 'created_at', 'updated_at'],
            ])
            ->assertJsonFragment(['name' => 'reports.generate'])
            ->assertJsonFragment(['description' => 'Generate reports']);

        expect(Permission::where('name', 'reports.generate')->exists())->toBeTrue();
    });

    test('creates permission without description when description is omitted', function (): void {
        $this->user->givePermissionTo('permissions.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/permissions', [
                'name' => 'reports.export',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.description', null);
    });
});

describe('GET /v1/permissions/{id} - Get Permission Details', function () {
    test('returns 401 when not authenticated', function (): void {
        $permission = Permission::where('name', 'employees.read')->first();

        $response = $this->getJson("/v1/permissions/{$permission->id}");

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks permissions.read permission', function (): void {
        $permission = Permission::where('name', 'employees.read')->first();

        $response = $this->withToken($this->token)
            ->getJson("/v1/permissions/{$permission->id}");

        $response->assertForbidden();
    });

    test('returns 404 when permission does not exist', function (): void {
        $this->user->givePermissionTo('permissions.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/permissions/999999');

        $response->assertNotFound();
    });

    test('returns permission details with assigned roles', function (): void {
        $this->user->givePermissionTo('permissions.read');

        $permission = Permission::where('name', 'employees.read')->first();
        $role = createPermissionManagementRole('Manager');
        $role->givePermissionTo('employees.read');

        $response = $this->withToken($this->token)
            ->getJson("/v1/permissions/{$permission->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'description', 'roles', 'created_at', 'updated_at'],
            ])
            ->assertJsonFragment(['name' => 'employees.read'])
            ->assertJsonCount(1, 'data.roles');
    });
});

describe('PATCH /v1/permissions/{id} - Update Permission', function () {
    test('returns 401 when not authenticated', function (): void {
        $permission = Permission::where('name', 'employees.read')->first();

        $response = $this->patchJson("/v1/permissions/{$permission->id}", [
            'description' => 'Updated description',
        ]);

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks permissions.update permission', function (): void {
        $this->user->givePermissionTo('permissions.read');
        $permission = Permission::where('name', 'employees.read')->first();

        $response = $this->withToken($this->token)
            ->patchJson("/v1/permissions/{$permission->id}", [
                'description' => 'Updated description',
            ]);

        $response->assertForbidden();
    });

    test('returns 404 when permission does not exist', function (): void {
        $this->user->givePermissionTo('permissions.update');

        $response = $this->withToken($this->token)
            ->patchJson('/v1/permissions/999999', [
                'description' => 'Updated description',
            ]);

        $response->assertNotFound();
    });

    test('returns 422 when trying to update name', function (): void {
        $this->user->givePermissionTo('permissions.update');
        $permission = Permission::where('name', 'employees.read')->first();

        $response = $this->withToken($this->token)
            ->patchJson("/v1/permissions/{$permission->id}", [
                'name' => 'employees.write',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('updates permission description successfully', function (): void {
        $this->user->givePermissionTo('permissions.update');
        $permission = Permission::where('name', 'employees.read')->first();

        $response = $this->withToken($this->token)
            ->patchJson("/v1/permissions/{$permission->id}", [
                'description' => 'View employee data',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['description' => 'View employee data']);

        expect($permission->fresh()->description)->toBe('View employee data');
    });
});

describe('DELETE /v1/permissions/{id} - Delete Permission', function () {
    test('returns 401 when not authenticated', function (): void {
        $permission = createPermissionManagementPermission('temp.permission');

        $response = $this->deleteJson("/v1/permissions/{$permission->id}");

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks permissions.delete permission', function (): void {
        $this->user->givePermissionTo('permissions.read');
        $permission = createPermissionManagementPermission('temp.permission');

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/permissions/{$permission->id}");

        $response->assertForbidden();
    });

    test('returns 404 when permission does not exist', function (): void {
        $this->user->givePermissionTo('permissions.delete');

        $response = $this->withToken($this->token)
            ->deleteJson('/v1/permissions/999999');

        $response->assertNotFound();
    });

    test('returns 422 when permission is assigned to roles', function (): void {
        $this->user->givePermissionTo('permissions.delete');
        $permission = Permission::where('name', 'employees.read')->first();
        $role = createPermissionManagementRole('Manager');
        $role->givePermissionTo('employees.read');

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/permissions/{$permission->id}");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot delete permission while assigned to roles'])
            ->assertJsonPath('assigned_to_roles', 1);
    });

    test('deletes permission successfully when not assigned', function (): void {
        $this->user->givePermissionTo('permissions.delete');
        $permission = createPermissionManagementPermission('temp.permission');

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/permissions/{$permission->id}");

        $response->assertNoContent();

        expect(Permission::where('name', 'temp.permission')->exists())->toBeFalse();
    });
});
