<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create tenant
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    // Create authenticated user with admin role
    $this->user = User::factory()->create();
    $this->user->assignRole('Admin');

    actingAs($this->user, 'sanctum');

    // Create root organizational unit for testing
    $this->rootUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Root Company',
        'type' => 'company',
    ]);

    // Give user admin scope on the root unit
    UserInternalOrganizationalScope::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'organizational_unit_id' => $this->rootUnit->id,
        'access_level' => 'admin',
    ]);
});

afterEach(function () {
    // Reset tenant context
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('OrganizationalUnitController - List', function () {
    test('user can list organizational units', function () {
        // Arrange: Create additional units
        $unit1 = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Department A',
            'type' => 'department',
        ]);
        $unit2 = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch B',
            'type' => 'branch',
        ]);

        // Act
        $response = getJson('/v1/organizational-units');

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'type', 'created_at', 'updated_at'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonCount(3, 'data'); // root + 2 created
    });

    test('list organizational units respects pagination', function () {
        // Arrange: Create 15 units
        for ($i = 1; $i <= 15; $i++) {
            OrganizationalUnit::factory()->create([
                'tenant_id' => $this->tenant->id,
                'name' => "Unit {$i}",
                'type' => 'department',
            ]);
        }

        // Act
        $response = getJson('/v1/organizational-units?per_page=10');

        // Assert
        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 16) // 15 + root
            ->assertJsonPath('meta.per_page', 10);
    });

    test('list organizational units can filter by type', function () {
        // Arrange
        OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'department',
        ]);
        OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'branch',
        ]);

        // Act
        $response = getJson('/v1/organizational-units?type=department');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'department');
    });
});

describe('OrganizationalUnitController - Create', function () {
    test('user can create organizational unit', function () {
        // Arrange
        $data = [
            'name' => 'New Department',
            'type' => 'department',
            'description' => 'A new department',
        ];

        // Act
        $response = postJson('/v1/organizational-units', $data);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Department')
            ->assertJsonPath('data.type', 'department')
            ->assertJsonPath('data.description', 'A new department');

        $this->assertDatabaseHas('organizational_units', [
            'name' => 'New Department',
            'type' => 'department',
            'tenant_id' => $this->tenant->id,
        ]);
    });

    test('user can create organizational unit with parent', function () {
        // Arrange
        $data = [
            'name' => 'Child Division',
            'type' => 'division',
            'parent_id' => $this->rootUnit->id,
        ];

        // Act
        $response = postJson('/v1/organizational-units', $data);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.name', 'Child Division');

        // Verify closure table entry
        $this->assertDatabaseHas('organizational_unit_closures', [
            'ancestor_id' => $this->rootUnit->id,
            'descendant_id' => OrganizationalUnit::where('name', 'Child Division')->first()->id,
            'depth' => 1,
        ]);
    });

    test('create organizational unit requires name', function () {
        // Arrange
        $data = ['type' => 'department'];

        // Act
        $response = postJson('/v1/organizational-units', $data);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('create organizational unit requires valid type', function () {
        // Arrange
        $data = ['name' => 'Test', 'type' => 'invalid_type'];

        // Act
        $response = postJson('/v1/organizational-units', $data);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });
});

describe('OrganizationalUnitController - Show', function () {
    test('user can view organizational unit', function () {
        // Act
        $response = getJson("/v1/organizational-units/{$this->rootUnit->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $this->rootUnit->id)
            ->assertJsonPath('data.name', 'Root Company');
    });

    test('show returns 404 for non-existent unit', function () {
        // Act
        $response = getJson('/v1/organizational-units/00000000-0000-0000-0000-000000000000');

        // Assert
        $response->assertNotFound();
    });
});

describe('OrganizationalUnitController - Update', function () {
    test('user can update organizational unit', function () {
        // Arrange
        $data = [
            'name' => 'Updated Company Name',
            'description' => 'Updated description',
        ];

        // Act
        $response = patchJson("/v1/organizational-units/{$this->rootUnit->id}", $data);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Company Name')
            ->assertJsonPath('data.description', 'Updated description');

        $this->assertDatabaseHas('organizational_units', [
            'id' => $this->rootUnit->id,
            'name' => 'Updated Company Name',
        ]);
    });
});

describe('OrganizationalUnitController - Delete', function () {
    test('user can delete organizational unit', function () {
        // Arrange: Create a unit as child of root (user has scope on root)
        $unitToDelete = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Unit to Delete',
            'type' => 'department',
        ]);
        $unitToDelete->setParent($this->rootUnit);

        // Give user explicit scope on the unit to delete
        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'organizational_unit_id' => $unitToDelete->id,
            'access_level' => 'admin',
        ]);

        // Act
        $response = deleteJson("/v1/organizational-units/{$unitToDelete->id}");

        // Assert
        $response->assertNoContent();

        // Verify soft delete
        $this->assertSoftDeleted('organizational_units', [
            'id' => $unitToDelete->id,
        ]);
    });
});

describe('OrganizationalUnitController - Hierarchy', function () {
    test('user can get descendants of unit', function () {
        // Arrange: Create hierarchy
        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Unit',
            'type' => 'department',
        ]);
        $child->setParent($this->rootUnit);

        $grandChild = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Grandchild Unit',
            'type' => 'division',
        ]);
        $grandChild->setParent($child);

        // Act
        $response = getJson("/v1/organizational-units/{$this->rootUnit->id}/descendants");

        // Assert
        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });

    test('user can get ancestors of unit', function () {
        // Arrange: Create hierarchy
        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Unit',
            'type' => 'department',
        ]);
        $child->setParent($this->rootUnit);

        // Act
        $response = getJson("/v1/organizational-units/{$child->id}/ancestors");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->rootUnit->id);
    });

    test('user can attach parent to unit', function () {
        // Arrange: Create orphan unit with scope
        $orphan = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Orphan Unit',
            'type' => 'department',
        ]);

        // Give user scope on orphan unit
        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orphan->id,
            'access_level' => 'admin',
        ]);

        // Act
        $response = postJson("/v1/organizational-units/{$orphan->id}/parent", [
            'parent_id' => $this->rootUnit->id,
        ]);

        // Assert
        $response->assertOk();

        // Verify closure table entry
        $this->assertDatabaseHas('organizational_unit_closures', [
            'ancestor_id' => $this->rootUnit->id,
            'descendant_id' => $orphan->id,
            'depth' => 1,
        ]);
    });

    test('user can detach parent from unit', function () {
        // Arrange: Create child with parent
        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Unit',
            'type' => 'department',
        ]);
        $child->setParent($this->rootUnit);

        // Act
        $response = deleteJson("/v1/organizational-units/{$child->id}/parent/{$this->rootUnit->id}");

        // Assert
        $response->assertOk();

        // Verify closure table entry removed
        $this->assertDatabaseMissing('organizational_unit_closures', [
            'ancestor_id' => $this->rootUnit->id,
            'descendant_id' => $child->id,
            'depth' => 1,
        ]);
    });
});
