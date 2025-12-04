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

    // Give user admin scope on the root unit with descendants
    UserInternalOrganizationalScope::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'organizational_unit_id' => $this->rootUnit->id,
        'access_level' => 'admin',
        'include_descendants' => true,
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
        // Arrange: Create additional units as children of root (so user has access via include_descendants)
        $unit1 = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Department A',
            'type' => 'department',
        ]);
        $unit1->setParent($this->rootUnit);

        $unit2 = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch B',
            'type' => 'branch',
        ]);
        $unit2->setParent($this->rootUnit);

        // Act
        $response = getJson('/v1/organizational-units');

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'type', 'created_at', 'updated_at'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'root_unit_ids'],
            ])
            ->assertJsonCount(3, 'data'); // root + 2 created
    });

    test('list organizational units respects pagination', function () {
        // Arrange: Create 15 units as children of root
        for ($i = 1; $i <= 15; $i++) {
            $unit = OrganizationalUnit::factory()->create([
                'tenant_id' => $this->tenant->id,
                'name' => "Unit {$i}",
                'type' => 'department',
            ]);
            $unit->setParent($this->rootUnit);
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
        // Arrange: Create units as children of root
        $dept = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'department',
        ]);
        $dept->setParent($this->rootUnit);

        $branch = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'branch',
        ]);
        $branch->setParent($this->rootUnit);

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

describe('OrganizationalUnitController - Permission-Based Filtering (Need-to-Know)', function () {
    test('returns only accessible units (Need-to-Know principle)', function () {
        // Arrange: Create hierarchy - Company -> Region -> Branch
        $region = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Region Berlin',
            'type' => 'region',
        ]);
        $region->setParent($this->rootUnit);

        $branch = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Berlin-Mitte',
            'type' => 'branch',
        ]);
        $branch->setParent($region);

        // Create another region that user should NOT have access to
        $otherRegion = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Region Munich',
            'type' => 'region',
        ]);
        $otherRegion->setParent($this->rootUnit);

        // Create user with scope only on Region Berlin (with descendants)
        $limitedUser = User::factory()->create();
        $limitedUser->assignRole('Admin');

        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $limitedUser->id,
            'organizational_unit_id' => $region->id,
            'access_level' => 'read',
            'include_descendants' => true,
        ]);

        actingAs($limitedUser, 'sanctum');

        // Act - No scope parameter needed, permission filtering is the default
        $response = getJson('/v1/organizational-units');

        // Assert: Should see Region Berlin and Branch Berlin-Mitte, NOT Company or Munich
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['meta' => ['root_unit_ids']]);

        $unitIds = collect($response->json('data'))->pluck('id')->toArray();
        expect($unitIds)->toContain($region->id)
            ->toContain($branch->id)
            ->not->toContain($this->rootUnit->id)
            ->not->toContain($otherRegion->id);
    });

    test('includes root_unit_ids in metadata', function () {
        // Arrange: User has scope on a region
        $region = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Region Hamburg',
            'type' => 'region',
        ]);
        $region->setParent($this->rootUnit);

        $limitedUser = User::factory()->create();
        $limitedUser->assignRole('Admin');

        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $limitedUser->id,
            'organizational_unit_id' => $region->id,
            'access_level' => 'manage',
            'include_descendants' => true,
        ]);

        actingAs($limitedUser, 'sanctum');

        // Act - Permission filtering is always applied (Need-to-Know principle)
        $response = getJson('/v1/organizational-units');

        // Assert: Region is the root for this user's view
        $response->assertOk()
            ->assertJsonStructure(['meta' => ['root_unit_ids']]);

        $rootUnitIds = $response->json('meta.root_unit_ids');
        expect($rootUnitIds)->toContain($region->id);
    });

    test('branch manager sees only own branch', function () {
        // Arrange: Create Company -> Region -> Branch hierarchy
        $region = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Region Berlin',
            'type' => 'region',
        ]);
        $region->setParent($this->rootUnit);

        $branch = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Berlin-Mitte',
            'type' => 'branch',
        ]);
        $branch->setParent($region);

        $otherBranch = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Berlin-Kreuzberg',
            'type' => 'branch',
        ]);
        $otherBranch->setParent($region);

        // Branch manager has scope only on own branch (no descendants flag matters)
        $branchManager = User::factory()->create();
        $branchManager->assignRole('Admin');

        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $branchManager->id,
            'organizational_unit_id' => $branch->id,
            'access_level' => 'manage',
            'include_descendants' => false,
        ]);

        actingAs($branchManager, 'sanctum');

        // Act - Permission filtering is always applied
        $response = getJson('/v1/organizational-units');

        // Assert: Only sees own branch
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $branch->id)
            ->assertJsonPath('meta.root_unit_ids.0', $branch->id);
    });

    test('user with multiple scopes sees union of accessible units', function () {
        // Arrange: Create two separate regions
        $region1 = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Region Berlin',
            'type' => 'region',
        ]);
        $region1->setParent($this->rootUnit);

        $region2 = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Region Hamburg',
            'type' => 'region',
        ]);
        $region2->setParent($this->rootUnit);

        // User has scope on both regions
        $multiScopeUser = User::factory()->create();
        $multiScopeUser->assignRole('Admin');

        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $multiScopeUser->id,
            'organizational_unit_id' => $region1->id,
            'access_level' => 'read',
        ]);

        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $multiScopeUser->id,
            'organizational_unit_id' => $region2->id,
            'access_level' => 'read',
        ]);

        actingAs($multiScopeUser, 'sanctum');

        // Act - Permission filtering is always applied
        $response = getJson('/v1/organizational-units');

        // Assert: Sees both regions
        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $unitIds = collect($response->json('data'))->pluck('id')->toArray();
        expect($unitIds)->toContain($region1->id)->toContain($region2->id);

        // Both are root units for this user's view
        $rootUnitIds = $response->json('meta.root_unit_ids');
        expect($rootUnitIds)->toContain($region1->id)->toContain($region2->id);
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
