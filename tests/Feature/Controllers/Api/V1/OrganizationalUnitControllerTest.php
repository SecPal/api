<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitClosure;
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

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property User $user
 * @property OrganizationalUnit $rootUnit
 */
beforeEach(function () {
    // Use process-specific KEK file for parallel test isolation
    incrementTestKekCounter();
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

    // Create authenticated user with explicit scoped access
    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    actingAs($this->user, 'sanctum');

    // Create root organizational unit for testing
    $this->rootUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Root Company',
        'type' => 'company',
    ]);

    // Give user manage scope on the root unit with descendants
    UserInternalOrganizationalScope::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'organizational_unit_id' => $this->rootUnit->id,
        'access_level' => 'manage',
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
                    '*' => ['id', 'name', 'type', 'is_legal_entity', 'is_establishment', 'is_active', 'is_assignable', 'created_at', 'updated_at'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'root_unit_ids'],
            ])
            ->assertJsonCount(3, 'data'); // root + 2 created
    });

    test('list response includes independent status flags', function () {
        $this->rootUnit->update([
            'is_legal_entity' => true,
            'is_establishment' => false,
        ]);

        $response = getJson('/v1/organizational-units');

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $this->rootUnit->id,
                'is_legal_entity' => true,
                'is_establishment' => false,
            ]);
    });

    test('list organizational units filters independent status flags', function () {
        $inactiveAssignable = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => false,
            'is_assignable' => true,
        ]);
        $inactiveAssignable->setParent($this->rootUnit);

        $activeUnassignable = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'is_assignable' => false,
        ]);
        $activeUnassignable->setParent($this->rootUnit);

        getJson('/v1/organizational-units?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactiveAssignable->id);

        getJson('/v1/organizational-units?is_assignable=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeUnassignable->id);

        getJson('/v1/organizational-units?is_active=0&is_assignable=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactiveAssignable->id);
    });

    test('list organizational units rejects non-boolean status filters', function () {
        getJson('/v1/organizational-units?is_active=not-a-boolean&is_assignable=not-a-boolean')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active', 'is_assignable']);
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

    test('list organizational units returns 422 when per_page exceeds maximum', function () {
        $response = getJson('/v1/organizational-units?per_page=1000');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
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

    test('list organizational units returns 422 for an invalid type filter', function () {
        $response = getJson('/v1/organizational-units?type=not-a-real-type');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    });

    test('list organizational units does not filter by null when type is sent as empty string', function () {
        $dept = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'department',
        ]);
        $dept->setParent($this->rootUnit);

        // Sending ?type= coerces to null via the nullable rule;
        // the filter must be skipped so all accessible units are returned.
        $response = getJson('/v1/organizational-units?type=');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2); // rootUnit + dept
    });

    test('list organizational units returns 422 for an invalid parent_id filter', function () {
        $response = getJson('/v1/organizational-units?parent_id=not-a-uuid');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    });

    test('list organizational units includes parent data when accessible', function () {
        // Arrange: Create child unit under root
        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Department',
            'type' => 'department',
        ]);
        $child->setParent($this->rootUnit);

        // Act
        $response = getJson('/v1/organizational-units');

        // Assert: Verify child has parent data
        $response->assertOk();
        $childData = collect($response->json('data'))->firstWhere('id', $child->id);
        expect($childData)->not->toBeNull()
            ->and($childData['parent'])->not->toBeNull()
            ->and($childData['parent']['id'])->toBe($this->rootUnit->id)
            ->and($childData['parent']['name'])->toBe('Root Company');
    });

    test('list organizational units does not leak inaccessible parent data', function () {
        // Arrange: Create a unit that user has no scope for (as potential parent)
        $inaccessibleParent = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inaccessible Parent',
            'type' => 'company',
        ]);

        // Create a child under the inaccessible parent
        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Accessible Child',
            'type' => 'department',
        ]);
        $child->setParent($inaccessibleParent);

        // Give user scope ONLY for the child (not the parent)
        $this->user->organizationalScopes()->delete();
        $this->user->organizationalScopes()->create([
            'organizational_unit_id' => $child->id,
            'include_descendants' => false,
            'access_level' => 'manage',
        ]);

        // Act
        $response = getJson('/v1/organizational-units');

        // Assert: Child should be returned but parent should be null (not accessible)
        $response->assertOk();
        $childData = collect($response->json('data'))->firstWhere('id', $child->id);
        expect($childData)->not->toBeNull()
            ->and($childData['parent'])->toBeNull();
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
            ->assertJsonPath('data.description', 'A new department')
            ->assertJsonPath('data.is_legal_entity', false)
            ->assertJsonPath('data.is_establishment', false)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_assignable', true);

        $this->assertDatabaseHas('organizational_units', [
            'name' => 'New Department',
            'type' => 'department',
            'tenant_id' => $this->tenant->id,
            'is_legal_entity' => false,
            'is_establishment' => false,
            'is_active' => true,
            'is_assignable' => true,
        ]);
    });

    test('create accepts all independent status flag combinations', function (bool $isLegalEntity, bool $isEstablishment) {
        $response = postJson('/v1/organizational-units', [
            'name' => sprintf('Status Unit %s %s', $isLegalEntity ? 'legal' : 'not-legal', $isEstablishment ? 'establishment' : 'not-establishment'),
            'type' => 'department',
            'is_legal_entity' => $isLegalEntity,
            'is_establishment' => $isEstablishment,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_legal_entity', $isLegalEntity)
            ->assertJsonPath('data.is_establishment', $isEstablishment);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $response->json('data.id'),
            'is_legal_entity' => $isLegalEntity,
            'is_establishment' => $isEstablishment,
        ]);
    })->with([
        'neither' => [false, false],
        'legal entity only' => [true, false],
        'establishment only' => [false, true],
        'both' => [true, true],
    ]);

    test('create strictly validates status flags as booleans', function () {
        postJson('/v1/organizational-units', [
            'name' => 'Invalid Status Unit',
            'type' => 'department',
            'is_legal_entity' => 'true',
            'is_establishment' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_legal_entity', 'is_establishment'])
            ->assertJsonPath('errors.is_legal_entity.0', 'The is_legal_entity field must be a JSON boolean (true or false).')
            ->assertJsonPath('errors.is_establishment.0', 'The is_establishment field must be a JSON boolean (true or false).');
    });

    test('create accepts all independent operational status flag combinations', function (bool $isActive, bool $isAssignable) {
        $response = postJson('/v1/organizational-units', [
            'name' => sprintf('Operational Status Unit %s %s', $isActive ? 'active' : 'inactive', $isAssignable ? 'assignable' : 'unassignable'),
            'type' => 'department',
            'is_active' => $isActive,
            'is_assignable' => $isAssignable,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_active', $isActive)
            ->assertJsonPath('data.is_assignable', $isAssignable);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $response->json('data.id'),
            'is_active' => $isActive,
            'is_assignable' => $isAssignable,
        ]);
    })->with([
        'inactive and unassignable' => [false, false],
        'active only' => [true, false],
        'assignable only' => [false, true],
        'active and assignable' => [true, true],
    ]);

    test('create strictly validates operational status flags as booleans', function () {
        postJson('/v1/organizational-units', [
            'name' => 'Invalid Operational Status Unit',
            'type' => 'department',
            'is_active' => 'true',
            'is_assignable' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active', 'is_assignable']);
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

    test('creates a bootstrap manage scope for an unassignable root unit', function (): void {
        $response = postJson('/v1/organizational-units', [
            'name' => 'Unassignable Root',
            'type' => 'holding',
            'is_assignable' => false,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('user_internal_organizational_scopes', [
            'user_id' => $this->user->id,
            'organizational_unit_id' => $response->json('data.id'),
            'access_level' => 'manage',
        ]);
    });

    test('creates a bootstrap manage scope for an unassignable child unit', function (): void {
        $response = postJson('/v1/organizational-units', [
            'name' => 'Unassignable Child',
            'type' => 'department',
            'parent_id' => $this->rootUnit->id,
            'is_assignable' => false,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('user_internal_organizational_scopes', [
            'user_id' => $this->user->id,
            'organizational_unit_id' => $response->json('data.id'),
            'access_level' => 'manage',
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

    test('create root organizational unit requires manage access on an existing unit', function () {
        $this->user->organizationalScopes()->delete();

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $this->rootUnit->id,
            'access_level' => 'read',
            'include_descendants' => false,
        ]);

        $response = postJson('/v1/organizational-units', [
            'name' => 'Unauthorized Root Holding',
            'type' => 'holding',
        ]);

        $response->assertForbidden();
    });

    test('create with non-existent parent UUID and sub-manage scope returns 403', function () {
        $this->user->organizationalScopes()->delete();

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $this->rootUnit->id,
            'access_level' => 'write',
            'include_descendants' => false,
        ]);

        $response = postJson('/v1/organizational-units', [
            'name' => 'Should Be Denied',
            'type' => 'holding',
            'parent_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertForbidden();
    });

    test('create with non-existent parent UUID and manage scope returns 422', function () {
        $this->user->organizationalScopes()->delete();

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $this->rootUnit->id,
            'access_level' => 'manage',
            'include_descendants' => false,
        ]);

        $response = postJson('/v1/organizational-units', [
            'name' => 'Should Fail Validation',
            'type' => 'holding',
            'parent_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    });

    test('creator automatically receives manage scope on new root unit', function () {
        // Arrange: Remove existing scopes so user has no access
        $this->user->organizationalScopes()->delete();

        // Give user a manage scope on an existing unit so root creation is authorized
        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $this->rootUnit->id,
            'access_level' => 'manage',
            'include_descendants' => false,
        ]);

        $data = [
            'name' => 'New Root Holding',
            'type' => 'holding',
        ];

        // Act
        $response = postJson('/v1/organizational-units', $data);

        // Assert: Unit was created
        $response->assertCreated();
        $newUnitId = $response->json('data.id');

        // Assert: Creator automatically has manage scope on new unit
        $this->assertDatabaseHas('user_internal_organizational_scopes', [
            'user_id' => $this->user->id,
            'organizational_unit_id' => $newUnitId,
            'access_level' => 'manage',
            'include_descendants' => true,
        ]);
    });

    test('newly created root unit is visible in list after creation', function () {
        // Arrange: Remove existing scopes so user has no access
        $this->user->organizationalScopes()->delete();

        // Give user a manage scope on an existing unit so root creation is authorized
        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $this->rootUnit->id,
            'access_level' => 'manage',
            'include_descendants' => false,
        ]);

        $data = [
            'name' => 'Brand New Holding',
            'type' => 'holding',
        ];

        // Act: Create the unit
        $createResponse = postJson('/v1/organizational-units', $data);
        $createResponse->assertCreated();
        $newUnitId = $createResponse->json('data.id');

        // Act: List units - the new unit should be visible
        $listResponse = getJson('/v1/organizational-units');

        // Assert: New unit appears in list and in root_unit_ids
        $listResponse->assertOk();
        $unitIds = collect($listResponse->json('data'))->pluck('id')->toArray();
        expect($unitIds)->toContain($newUnitId);

        $rootUnitIds = $listResponse->json('meta.root_unit_ids');
        expect($rootUnitIds)->toContain($newUnitId);
    });

    test('child unit inherits access from parent scope with include_descendants', function () {
        // Arrange: User already has manage scope on rootUnit with include_descendants=true (from beforeEach)
        $data = [
            'name' => 'Child Department',
            'type' => 'department',
            'parent_id' => $this->rootUnit->id,
        ];

        // Act: Create child unit
        $createResponse = postJson('/v1/organizational-units', $data);
        $createResponse->assertCreated();
        $childUnitId = $createResponse->json('data.id');

    });

    test('creator receives direct manage scope on a newly created child unit', function () {
        givePermissionWithTenant($this->user, $this->tenant->id, 'organizational_scopes.manage');

        $this->user->organizationalScopes()->delete();

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $this->rootUnit->id,
            'access_level' => 'manage',
            'include_descendants' => false,
        ]);

        $createResponse = postJson('/v1/organizational-units', [
            'name' => 'Field Team Alpha',
            'type' => 'department',
            'parent_id' => $this->rootUnit->id,
        ]);

        $createResponse->assertCreated();

        $childUnitId = $createResponse->json('data.id');

        $this->assertDatabaseHas('user_internal_organizational_scopes', [
            'user_id' => $this->user->id,
            'organizational_unit_id' => $childUnitId,
            'access_level' => 'manage',
            'include_descendants' => false,
        ]);

        $createResponse->assertJsonPath('data.permissions.delete', true)
            ->assertJsonPath('data.permissions.manage_scopes', true);

        getJson('/v1/organizational-units')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $childUnitId,
                'name' => 'Field Team Alpha',
            ]);

        patchJson("/v1/organizational-units/{$childUnitId}", [
            'description' => 'Updated after create',
        ])->assertOk()
            ->assertJsonPath('data.description', 'Updated after create');
    });

    test('create child response includes accessible parent data for cache consistency', function () {
        $response = postJson('/v1/organizational-units', [
            'name' => 'Cached Child Unit',
            'type' => 'department',
            'parent_id' => $this->rootUnit->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.parent.id', $this->rootUnit->id)
            ->assertJsonPath('data.parent.name', $this->rootUnit->name);
    });

    test('create child repairs missing parent self-closure before attaching hierarchy', function () {
        OrganizationalUnitClosure::where('ancestor_id', $this->rootUnit->id)
            ->where('descendant_id', $this->rootUnit->id)
            ->delete();

        $response = postJson('/v1/organizational-units', [
            'name' => 'Recovered Child Unit',
            'type' => 'region',
            'parent_id' => $this->rootUnit->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.parent.id', $this->rootUnit->id)
            ->assertJsonPath('data.parent.name', $this->rootUnit->name);

        $childId = $response->json('data.id');

        $this->assertDatabaseHas('organizational_unit_closures', [
            'ancestor_id' => $this->rootUnit->id,
            'descendant_id' => $this->rootUnit->id,
            'depth' => 0,
        ]);

        $this->assertDatabaseHas('organizational_unit_closures', [
            'ancestor_id' => $this->rootUnit->id,
            'descendant_id' => $childId,
            'depth' => 1,
        ]);
    });

    // Issue #301: Hierarchy Validation Tests
    test('cannot create company under branch (hierarchy violation)', function () {
        // Arrange: Create a branch as parent
        $branch = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'branch',
            'name' => 'Berlin Branch',
        ]);
        $branch->setParent($this->rootUnit);

        // Act: Try to create a company (rank 2) under branch (rank 4)
        $response = postJson('/v1/organizational-units', [
            'name' => 'New Company',
            'type' => 'company',
            'parent_id' => $branch->id,
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });

    test('cannot create holding under company (hierarchy violation)', function () {
        // Arrange: rootUnit is a company (rank 2)
        $this->rootUnit->update(['type' => 'company']);

        // Act: Try to create a holding (rank 1) under company (rank 2)
        $response = postJson('/v1/organizational-units', [
            'name' => 'New Holding',
            'type' => 'holding',
            'parent_id' => $this->rootUnit->id,
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });

    test('can create department under branch (valid hierarchy)', function () {
        // Arrange: Create a branch as parent
        $branch = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'branch',
            'name' => 'Munich Branch',
        ]);
        $branch->setParent($this->rootUnit);

        // Act: Create department (rank 6) under branch (rank 4) - valid
        $response = postJson('/v1/organizational-units', [
            'name' => 'HR Department',
            'type' => 'department',
            'parent_id' => $branch->id,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.name', 'HR Department')
            ->assertJsonPath('data.type', 'department');
    });

    test('cannot create same type under same type (same-level nesting forbidden)', function () {
        // Arrange: rootUnit is a branch
        $this->rootUnit->update(['type' => 'branch']);

        // Act: Try to create another branch under branch (same rank = invalid)
        $response = postJson('/v1/organizational-units', [
            'name' => 'Sub-Branch',
            'type' => 'branch',
            'parent_id' => $this->rootUnit->id,
        ]);

        // Assert: Same-level nesting is not allowed
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });

    test('custom type can be created under any type (lowest rank)', function () {
        // Arrange: Create a branch
        $branch = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'branch',
            'name' => 'Test Branch',
        ]);
        $branch->setParent($this->rootUnit);

        // Act: Create custom type under branch
        $response = postJson('/v1/organizational-units', [
            'name' => 'Special Team',
            'type' => 'custom',
            'custom_type_name' => 'Security Team',
            'parent_id' => $branch->id,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.type', 'custom');
    });

    test('cannot create any type under custom type (custom is lowest)', function () {
        // Arrange: Create a custom type unit
        $customUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'custom',
            'custom_type_name' => 'Project Team',
            'name' => 'Alpha Team',
        ]);
        $customUnit->setParent($this->rootUnit);

        // Act: Try to create department under custom
        $response = postJson('/v1/organizational-units', [
            'name' => 'Sub Department',
            'type' => 'department',
            'parent_id' => $customUnit->id,
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });

    test('root units can be any type (no parent constraint)', function () {
        // Act: Create root units of different types
        $responses = collect(['holding', 'company', 'region', 'branch', 'division', 'department'])
            ->map(fn ($type) => postJson('/v1/organizational-units', [
                'name' => "Root {$type}",
                'type' => $type,
            ]));

        // Assert: All should succeed
        $responses->each(fn ($response) => $response->assertCreated());
    });
});

describe('OrganizationalUnitController - Show', function () {
    test('user can view organizational unit', function () {
        // Act
        $response = getJson("/v1/organizational-units/{$this->rootUnit->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $this->rootUnit->id)
            ->assertJsonPath('data.name', 'Root Company')
            ->assertJsonPath('data.is_legal_entity', false)
            ->assertJsonPath('data.is_establishment', false)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_assignable', true);
    });

    test('show response exposes action permissions and accessible parent data', function () {
        givePermissionWithTenant($this->user, $this->tenant->id, 'organizational_scopes.manage');

        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Showable Child',
            'type' => 'department',
        ]);
        $child->setParent($this->rootUnit);

        $response = getJson("/v1/organizational-units/{$child->id}");

        $response->assertOk()
            ->assertJsonPath('data.parent.id', $this->rootUnit->id)
            ->assertJsonPath('data.parent.name', $this->rootUnit->name)
            ->assertJsonPath('data.permissions.create_child', true)
            ->assertJsonPath('data.permissions.update', true)
            ->assertJsonPath('data.permissions.delete', true)
            ->assertJsonPath('data.permissions.manage_scopes', true);
    });

    test('show returns 404 for non-existent unit', function () {
        // Act
        $response = getJson('/v1/organizational-units/00000000-0000-0000-0000-000000000000');

        // Assert
        $response->assertNotFound();
    });

    test('show returns 404 for invalid unit id format', function () {
        // Act
        $response = getJson('/v1/organizational-units/1');

        // Assert
        $response->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
            ]);
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
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.is_legal_entity', false)
            ->assertJsonPath('data.is_establishment', false);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $this->rootUnit->id,
            'name' => 'Updated Company Name',
        ]);
    });

    test('updating an organizational unit to a custom type requires a custom type name', function () {
        patchJson("/v1/organizational-units/{$this->rootUnit->id}", [
            'type' => 'custom',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['custom_type_name'])
            ->assertJsonPath('errors.custom_type_name.0', 'The custom type name is required when type is "custom".');
    });

    test('clearing the name of an existing custom organizational unit is rejected', function () {
        $this->rootUnit->update([
            'type' => 'custom',
            'custom_type_name' => 'Security Team',
        ]);

        patchJson("/v1/organizational-units/{$this->rootUnit->id}", [
            'custom_type_name' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['custom_type_name'])
            ->assertJsonPath('errors.custom_type_name.0', 'The custom type name is required when type is "custom".');

        $this->rootUnit->refresh();

        expect($this->rootUnit->custom_type_name)->toBe('Security Team');
    });

    test('updating another field on a custom organizational unit preserves its custom type name', function () {
        $this->rootUnit->update([
            'type' => 'custom',
            'custom_type_name' => 'Security Team',
        ]);

        patchJson("/v1/organizational-units/{$this->rootUnit->id}", [
            'description' => 'Updated description',
        ])->assertOk()
            ->assertJsonPath('data.custom_type_name', 'Security Team')
            ->assertJsonPath('data.description', 'Updated description');
    });

    test('resending the existing custom type requires its custom type name', function () {
        $this->rootUnit->update([
            'type' => 'custom',
            'custom_type_name' => 'Security Team',
        ]);

        patchJson("/v1/organizational-units/{$this->rootUnit->id}", [
            'type' => 'custom',
            'description' => 'Updated description',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['custom_type_name'])
            ->assertJsonPath('errors.custom_type_name.0', 'The custom type name is required when type is "custom".');

        $this->rootUnit->refresh();

        expect($this->rootUnit->custom_type_name)->toBe('Security Team')
            ->and($this->rootUnit->description)->not->toBe('Updated description');
    });

    test('patch accepts all independent status flag combinations', function (bool $isLegalEntity, bool $isEstablishment) {
        $response = patchJson("/v1/organizational-units/{$this->rootUnit->id}", [
            'is_legal_entity' => $isLegalEntity,
            'is_establishment' => $isEstablishment,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_legal_entity', $isLegalEntity)
            ->assertJsonPath('data.is_establishment', $isEstablishment);

        $this->rootUnit->refresh();

        expect($this->rootUnit->is_legal_entity)->toBe($isLegalEntity)
            ->and($this->rootUnit->is_establishment)->toBe($isEstablishment);
    })->with([
        'neither' => [false, false],
        'legal entity only' => [true, false],
        'establishment only' => [false, true],
        'both' => [true, true],
    ]);

    test('patching one status flag leaves the other unchanged', function () {
        $this->rootUnit->update([
            'is_legal_entity' => true,
            'is_establishment' => true,
        ]);

        patchJson("/v1/organizational-units/{$this->rootUnit->id}", [
            'is_legal_entity' => false,
        ])->assertOk()
            ->assertJsonPath('data.is_legal_entity', false)
            ->assertJsonPath('data.is_establishment', true);

        patchJson("/v1/organizational-units/{$this->rootUnit->id}", [
            'is_establishment' => false,
        ])->assertOk()
            ->assertJsonPath('data.is_legal_entity', false)
            ->assertJsonPath('data.is_establishment', false);
    });

    test('patch strictly validates status flags as booleans', function () {
        patchJson("/v1/organizational-units/{$this->rootUnit->id}", [
            'is_legal_entity' => 'false',
            'is_establishment' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_legal_entity', 'is_establishment'])
            ->assertJsonPath('errors.is_legal_entity.0', 'The is_legal_entity field must be a JSON boolean (true or false).')
            ->assertJsonPath('errors.is_establishment.0', 'The is_establishment field must be a JSON boolean (true or false).');
    });

    test('patch accepts all independent operational status flag combinations', function (bool $isActive, bool $isAssignable) {
        patchJson("/v1/organizational-units/{$this->rootUnit->id}", [
            'is_active' => $isActive,
            'is_assignable' => $isAssignable,
        ])->assertOk()
            ->assertJsonPath('data.is_active', $isActive)
            ->assertJsonPath('data.is_assignable', $isAssignable);

        $this->rootUnit->refresh();

        expect($this->rootUnit->is_active)->toBe($isActive)
            ->and($this->rootUnit->is_assignable)->toBe($isAssignable);
    })->with([
        'inactive and unassignable' => [false, false],
        'active only' => [true, false],
        'assignable only' => [false, true],
        'active and assignable' => [true, true],
    ]);

    test('patch strictly validates operational status flags as booleans', function () {
        patchJson("/v1/organizational-units/{$this->rootUnit->id}", [
            'is_active' => 'false',
            'is_assignable' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active', 'is_assignable']);
    });
});

describe('OrganizationalUnitController - Delete', function () {
    test('user can delete organizational unit without children', function () {
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
            'access_level' => 'manage',
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

    test('delete is blocked when unit has children (409 Conflict)', function () {
        // Arrange: Create a unit with children
        $parentUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parent Unit',
            'type' => 'region',
        ]);
        $parentUnit->setParent($this->rootUnit);

        // Create 3 child units
        for ($i = 1; $i <= 3; $i++) {
            $child = OrganizationalUnit::factory()->create([
                'tenant_id' => $this->tenant->id,
                'name' => "Child Unit {$i}",
                'type' => 'branch',
            ]);
            $child->setParent($parentUnit);
        }

        // Give user explicit scope on the parent unit
        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'organizational_unit_id' => $parentUnit->id,
            'access_level' => 'manage',
            'include_descendants' => true,
        ]);

        // Act
        $response = deleteJson("/v1/organizational-units/{$parentUnit->id}");

        // Assert: Should return 409 Conflict
        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Cannot delete: 3 child units exist',
                'child_count' => 3,
                'hint' => 'Delete or move child units first',
            ]);

        // Verify unit is NOT deleted
        $this->assertDatabaseHas('organizational_units', [
            'id' => $parentUnit->id,
            'deleted_at' => null,
        ]);
    });

    test('delete succeeds after children are removed', function () {
        // Arrange: Create a unit with one child
        $parentUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parent Unit',
            'type' => 'region',
        ]);
        $parentUnit->setParent($this->rootUnit);

        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Unit',
            'type' => 'branch',
        ]);
        $child->setParent($parentUnit);

        // Give user manage scope
        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'organizational_unit_id' => $parentUnit->id,
            'access_level' => 'manage',
            'include_descendants' => true,
        ]);

        // First attempt: Should be blocked
        $response = deleteJson("/v1/organizational-units/{$parentUnit->id}");
        $response->assertStatus(409);

        // Move child to root (reparent)
        $child->setParent($this->rootUnit);

        // Second attempt: Should succeed now
        $response = deleteJson("/v1/organizational-units/{$parentUnit->id}");
        $response->assertNoContent();

        $this->assertSoftDeleted('organizational_units', [
            'id' => $parentUnit->id,
        ]);
    });

    test('delete succeeds after children are deleted (soft-deleted children should not block)', function () {
        // Arrange: Create a unit with one child - Issue #295 regression test
        $parentUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parent Unit',
            'type' => 'region',
        ]);
        $parentUnit->setParent($this->rootUnit);

        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Unit',
            'type' => 'branch',
        ]);
        $child->setParent($parentUnit);

        // Give user manage scope on parent with descendants
        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'organizational_unit_id' => $parentUnit->id,
            'access_level' => 'manage',
            'include_descendants' => true,
        ]);

        // First attempt: Should be blocked because child exists
        $response = deleteJson("/v1/organizational-units/{$parentUnit->id}");
        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Cannot delete: 1 child unit exists',
                'child_count' => 1,
            ]);

        // Delete the child unit (soft delete)
        $response = deleteJson("/v1/organizational-units/{$child->id}");
        $response->assertNoContent();
        $this->assertSoftDeleted('organizational_units', ['id' => $child->id]);

        // Second attempt: Should succeed now because child was deleted
        $response = deleteJson("/v1/organizational-units/{$parentUnit->id}");
        $response->assertNoContent();

        $this->assertSoftDeleted('organizational_units', [
            'id' => $parentUnit->id,
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
        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $limitedUser->id,
            'organizational_unit_id' => $region->id,
            'access_level' => 'read',
            'include_descendants' => true,
        ]);

        actingAs($limitedUser, 'sanctum');

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

    test('user cannot access organizational units from different tenant (cross-tenant isolation)', function () {
        // Arrange: Create a second tenant with its own organizational unit
        $otherTenantKeys = TenantKey::generateEnvelopeKeys();
        $otherTenant = TenantKey::create($otherTenantKeys);

        $otherTenantUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Tenant Unit',
            'type' => 'company',
        ]);

        // Act: Attempt to access with current user (who belongs to $this->tenant)
        $response = getJson('/v1/organizational-units');

        // Assert: Other tenant's unit is NOT visible
        $response->assertOk();
        $unitIds = collect($response->json('data'))->pluck('id')->toArray();
        expect($unitIds)->not->toContain($otherTenantUnit->id);
    });

    test('update response retains accessible parent data for cache consistency', function () {
        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Editable Child',
            'type' => 'department',
        ]);
        $child->setParent($this->rootUnit);

        $response = patchJson("/v1/organizational-units/{$child->id}", [
            'description' => 'Parent should stay present',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.parent.id', $this->rootUnit->id)
            ->assertJsonPath('data.parent.name', $this->rootUnit->name)
            ->assertJsonPath('data.description', 'Parent should stay present');
    });
});

describe('OrganizationalUnitController - Hierarchy', function () {
    test('user can get descendants of unit', function () {
        // Arrange: Create hierarchy
        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Unit',
            'type' => 'department',
            'is_active' => false,
            'is_assignable' => true,
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
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'id' => $child->id,
                'is_active' => false,
                'is_assignable' => true,
            ]);
    });

    test('user can get ancestors of unit', function () {
        // Arrange: Create hierarchy
        $this->rootUnit->update([
            'is_active' => true,
            'is_assignable' => false,
        ]);

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
            ->assertJsonPath('data.0.id', $this->rootUnit->id)
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.0.is_assignable', false);
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
            'access_level' => 'manage',
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

    test('attach parent validates parent_id as a UUID', function () {
        $orphan = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Orphan Unit',
            'type' => 'department',
        ]);

        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orphan->id,
            'access_level' => 'manage',
        ]);

        $response = postJson("/v1/organizational-units/{$orphan->id}/parent", [
            'parent_id' => 'not-a-uuid',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    });

    test('attach parent rejects cross-tenant parent ids during validation', function () {
        $orphan = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Orphan Unit',
            'type' => 'department',
        ]);

        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orphan->id,
            'access_level' => 'manage',
        ]);

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $foreignParent = OrganizationalUnit::factory()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Foreign Parent',
            'type' => 'company',
        ]);

        $response = postJson("/v1/organizational-units/{$orphan->id}/parent", [
            'parent_id' => $foreignParent->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    });

    test('attach parent rejects soft deleted parent ids during validation', function () {
        $orphan = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Orphan Unit',
            'type' => 'department',
        ]);

        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orphan->id,
            'access_level' => 'manage',
        ]);

        $deletedParent = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deleted Parent',
            'type' => 'company',
        ]);
        $deletedParent->delete();

        $response = postJson("/v1/organizational-units/{$orphan->id}/parent", [
            'parent_id' => $deletedParent->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    });

    test('user keeps prior moved-unit access without inheriting stronger new-parent privileges', function () {
        $this->user->organizationalScopes()->delete();
        $this->user->unsetRelation('organizationalScopes');

        $sourceRoot = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Source Root',
            'type' => 'company',
        ]);

        $alternateRoot = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Alternate Root',
            'type' => 'company',
        ]);

        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transfer Unit',
            'type' => 'department',
        ]);
        $child->setParent($sourceRoot);

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $sourceRoot->id,
            'access_level' => 'write',
            'include_descendants' => true,
        ]);

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $alternateRoot->id,
            'access_level' => 'manage',
            'include_descendants' => false,
        ]);

        $this->user->unsetRelation('organizationalScopes');

        expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue()
            ->and($this->user->hasAccessToUnit($child, 'manage'))->toBeFalse();

        $response = postJson("/v1/organizational-units/{$child->id}/parent", [
            'parent_id' => $alternateRoot->id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('user_internal_organizational_scopes', [
            'user_id' => $this->user->id,
            'organizational_unit_id' => $child->id,
            'access_level' => 'write',
            'include_descendants' => false,
        ]);

        $this->assertDatabaseMissing('user_internal_organizational_scopes', [
            'user_id' => $this->user->id,
            'organizational_unit_id' => $child->id,
            'access_level' => 'manage',
        ]);

        $this->user->refresh();
        $child->refresh();

        expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue()
            ->and($this->user->hasAccessToUnit($child, 'manage'))->toBeFalse();

        getJson('/v1/organizational-units')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $child->id,
                'name' => 'Transfer Unit',
            ]);

        patchJson("/v1/organizational-units/{$child->id}", [
            'description' => 'Updated after move',
        ])->assertOk()
            ->assertJsonPath('data.description', 'Updated after move');

        deleteJson("/v1/organizational-units/{$child->id}")
            ->assertForbidden();
    });

    test('attach parent response includes the new accessible parent data for cache consistency', function () {
        $orphan = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Attachable Unit',
            'type' => 'department',
        ]);

        $existingChild = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Existing Child',
            'type' => 'division',
        ]);
        $existingChild->setParent($orphan);

        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orphan->id,
            'access_level' => 'manage',
            'include_descendants' => true,
        ]);

        $response = postJson("/v1/organizational-units/{$orphan->id}/parent", [
            'parent_id' => $this->rootUnit->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.parent.id', $this->rootUnit->id)
            ->assertJsonPath('data.parent.name', $this->rootUnit->name);
    });

    test('attach parent is forbidden when a previously inaccessible descendant would inherit destination access', function () {
        $this->user->organizationalScopes()->delete();
        $this->user->unsetRelation('organizationalScopes');

        $sourceRoot = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Source Root',
            'type' => 'company',
        ]);

        $alternateRoot = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Alternate Root',
            'type' => 'company',
        ]);

        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transfer Unit',
            'type' => 'department',
        ]);
        $child->setParent($sourceRoot);

        $grandchild = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hidden Descendant',
            'type' => 'division',
        ]);
        $grandchild->setParent($child);

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $child->id,
            'access_level' => 'manage',
            'include_descendants' => false,
        ]);

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $alternateRoot->id,
            'access_level' => 'manage',
            'include_descendants' => true,
        ]);

        $this->user->unsetRelation('organizationalScopes');

        expect($this->user->hasAccessToUnit($child, 'manage'))->toBeTrue()
            ->and($this->user->hasAccessToUnit($grandchild))->toBeFalse();

        $response = postJson("/v1/organizational-units/{$child->id}/parent", [
            'parent_id' => $alternateRoot->id,
        ]);

        $response->assertForbidden();

        $child->refresh();
        $grandchild->refresh();
        $this->user->refresh();

        expect($child->parent?->id)->toBe($sourceRoot->id)
            ->and($grandchild->parent?->id)->toBe($child->id)
            ->and($this->user->hasAccessToUnit($grandchild))->toBeFalse();
    });

    test('attach parent is forbidden when moving a leaf would expose future descendants through destination inheritance', function () {
        $this->user->organizationalScopes()->delete();
        $this->user->unsetRelation('organizationalScopes');

        $sourceRoot = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Source Root',
            'type' => 'company',
        ]);

        $alternateRoot = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Alternate Root',
            'type' => 'company',
        ]);

        $leaf = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transfer Leaf',
            'type' => 'department',
        ]);
        $leaf->setParent($sourceRoot);

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $leaf->id,
            'access_level' => 'manage',
            'include_descendants' => false,
        ]);

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $alternateRoot->id,
            'access_level' => 'manage',
            'include_descendants' => true,
        ]);

        $this->user->unsetRelation('organizationalScopes');

        expect($this->user->hasAccessToUnit($leaf, 'manage'))->toBeTrue();

        $response = postJson("/v1/organizational-units/{$leaf->id}/parent", [
            'parent_id' => $alternateRoot->id,
        ]);

        $response->assertForbidden();

        $leaf->refresh();

        expect($leaf->parent?->id)->toBe($sourceRoot->id);
    });

    test('user can detach parent from unit when they have direct scope', function () {
        // Arrange: Create child with parent
        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Unit',
            'type' => 'department',
        ]);
        $child->setParent($this->rootUnit);

        // User needs direct scope on child to be able to detach it from parent
        // (otherwise they would lose access after detach)
        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'organizational_unit_id' => $child->id,
            'access_level' => 'manage',
            'include_descendants' => false,
        ]);

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

    test('user loses access to unit after detaching from parent when access was via include_descendants', function () {
        // Scenario: User has access to rootUnit with include_descendants.
        // Child is created under rootUnit, so user has inherited access.
        // Trying to detach child from rootUnit should be PREVENTED because
        // the user would lose access after the operation.

        // Arrange: Create child with parent
        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Unit',
            'type' => 'department',
        ]);
        $child->setParent($this->rootUnit);

        // Verify user has access before detach (via include_descendants)
        expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue();

        // Act: Try to detach parent (make child a root unit)
        // This should be FORBIDDEN because user has no direct scope on child
        $response = deleteJson("/v1/organizational-units/{$child->id}/parent/{$this->rootUnit->id}");

        // Assert: Operation should be forbidden
        $response->assertForbidden();
        $response->assertJsonPath('message', 'Cannot make this unit a root unit. Your access to this unit is inherited from the parent hierarchy. Making it a root unit would remove your access. Please contact someone with organizational scope management access to grant direct access to this unit first.');

        // Verify the closure table entry is still there (parent not detached)
        $this->assertDatabaseHas('organizational_unit_closures', [
            'ancestor_id' => $this->rootUnit->id,
            'descendant_id' => $child->id,
            'depth' => 1,
        ]);

        // User should still have access (nothing changed)
        $this->user->refresh();
        $child->refresh();
        expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue();
    });

    test('german gettext catalog replaces legacy root-unit administrator wording', function () {
        $catalog = file_get_contents(lang_path('de/LC_MESSAGES/messages.po'));

        expect($catalog)->toContain(
            'msgid "Cannot make this unit a root unit. Your access to this unit is inherited from the parent hierarchy. Making it a root unit would remove your access. Please contact someone with organizational scope management access to grant direct access to this unit first."'
        )->toContain(
            'msgstr "Diese Einheit kann nicht zu einer Stammeinheit gemacht werden. Ihr Zugriff auf diese Einheit wird von der übergeordneten Hierarchie geerbt. Wenn Sie diese Einheit zur Stammeinheit machen, wird Ihr Zugriff aufgehoben. Bitte wenden Sie sich zunächst an eine Person mit Berechtigung zur Verwaltung organisatorischer Geltungsbereiche, um direkten Zugriff auf diese Einheit zu erhalten."'
        )->not->toContain(
            'Bitte wenden Sie sich zunächst an einen Administrator'
        );
    });

    test('deleting a parent preserves closure access to trashed descendants', function () {
        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deleted Child Unit',
            'type' => 'department',
        ]);
        $child->setParent($this->rootUnit);

        $child->delete();

        $response = deleteJson("/v1/organizational-units/{$this->rootUnit->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('organizational_units', [
            'id' => $this->rootUnit->id,
        ]);

        $this->assertDatabaseHas('organizational_unit_closures', [
            'ancestor_id' => $this->rootUnit->id,
            'descendant_id' => $child->id,
            'depth' => 1,
        ]);
    });

    test('user retains access to unit after detaching if they have direct scope', function () {
        // Scenario: User has direct scope on the child unit.
        // After detaching from parent, user should still have access.

        // Arrange: Create child with parent
        $child = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Unit',
            'type' => 'department',
        ]);
        $child->setParent($this->rootUnit);

        // Give user direct scope on the child unit
        UserInternalOrganizationalScope::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'organizational_unit_id' => $child->id,
            'access_level' => 'manage',
            'include_descendants' => false,
        ]);

        // Verify user has access before detach
        expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue();

        // Act: Detach parent (make child a root unit)
        $response = deleteJson("/v1/organizational-units/{$child->id}/parent/{$this->rootUnit->id}");
        $response->assertOk();

        // Refresh
        $this->user->refresh();
        $child->refresh();

        // Assert: User should still have access (via direct scope)
        expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue();

        // Trying to update should succeed
        $updateResponse = patchJson("/v1/organizational-units/{$child->id}", [
            'name' => 'Updated Name',
        ]);
        $updateResponse->assertOk();
    });
});
