<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Customer;
use App\Models\ObjectArea;
use App\Models\OrganizationalUnit;
use App\Models\SecPalObject;
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

    // Create organizational unit
    $this->orgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    // Give user admin scope
    UserInternalOrganizationalScope::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'admin',
    ]);

    // Create customer
    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'managed_by_organizational_unit_id' => $this->orgUnit->id,
    ]);

    // Create object
    $this->object = SecPalObject::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
    ]);
});

afterEach(function () {
    // Reset tenant context
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('SecPalObjectController - List', function () {
    test('user can list objects', function () {
        // Arrange
        SecPalObject::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
        ]);

        // Act
        $response = getJson('/v1/objects');

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'object_number', 'name', 'address', 'created_at'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonCount(2, 'data');
    });

    test('list objects can filter by customer_id', function () {
        // Arrange: Create another customer and object
        $otherCustomer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'managed_by_organizational_unit_id' => $this->orgUnit->id,
        ]);
        SecPalObject::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $otherCustomer->id,
        ]);

        // Act
        $response = getJson("/v1/objects?customer_id={$this->customer->id}");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->object->id);
    });
});

describe('SecPalObjectController - Create', function () {
    test('user can create object', function () {
        // Arrange
        $data = [
            'customer_id' => $this->customer->id,
            'object_number' => 'OBJ-NEW-001',
            'name' => 'New Building',
            'address' => '456 Oak Street',
        ];

        // Act
        $response = postJson('/v1/objects', $data);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Building')
            ->assertJsonPath('data.object_number', 'OBJ-NEW-001');

        $this->assertDatabaseHas('objects', [
            'name' => 'New Building',
            'object_number' => 'OBJ-NEW-001',
        ]);
    });

    test('create object with GPS coordinates', function () {
        // Arrange
        $data = [
            'customer_id' => $this->customer->id,
            'object_number' => 'OBJ-GPS',
            'name' => 'GPS Location',
            'address' => '789 GPS Lane',
            'gps_coordinates' => [
                'lat' => 52.5200,
                'lon' => 13.4050,
            ],
        ];

        // Act
        $response = postJson('/v1/objects', $data);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.gps_coordinates.lat', 52.52)
            ->assertJsonPath('data.gps_coordinates.lon', 13.405);
    });

    test('create object requires customer_id', function () {
        // Arrange
        $data = [
            'object_number' => 'OBJ-NO-CUST',
            'name' => 'No Customer',
            'address' => 'Unknown',
        ];

        // Act
        $response = postJson('/v1/objects', $data);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);
    });
});

describe('SecPalObjectController - Show', function () {
    test('user can view object', function () {
        // Act
        $response = getJson("/v1/objects/{$this->object->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $this->object->id)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'object_number', 'address', 'customer'],
            ]);
    });
});

describe('SecPalObjectController - Update', function () {
    test('user can update object', function () {
        // Arrange
        $data = [
            'name' => 'Updated Building Name',
            'address' => 'Updated Address',
        ];

        // Act
        $response = patchJson("/v1/objects/{$this->object->id}", $data);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Building Name')
            ->assertJsonPath('data.address', 'Updated Address');
    });
});

describe('SecPalObjectController - Delete', function () {
    test('user can delete object', function () {
        // Arrange
        $objectToDelete = SecPalObject::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
        ]);

        // Act
        $response = deleteJson("/v1/objects/{$objectToDelete->id}");

        // Assert
        $response->assertNoContent();
        $this->assertSoftDeleted('objects', ['id' => $objectToDelete->id]);
    });
});

describe('SecPalObjectController - Areas', function () {
    test('user can get areas of object', function () {
        // Arrange
        ObjectArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'object_id' => $this->object->id,
            'name' => 'Main Entrance',
        ]);
        ObjectArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'object_id' => $this->object->id,
            'name' => 'Parking Lot',
        ]);

        // Act
        $response = getJson("/v1/objects/{$this->object->id}/areas");

        // Assert
        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });

    test('user can create area for object', function () {
        // Arrange
        $data = [
            'name' => 'New Area',
            'description' => 'A new area description',
            'requires_separate_guard_book' => true,
        ];

        // Act
        $response = postJson("/v1/objects/{$this->object->id}/areas", $data);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Area')
            ->assertJsonPath('data.requires_separate_guard_book', true);

        $this->assertDatabaseHas('object_areas', [
            'name' => 'New Area',
            'object_id' => $this->object->id,
        ]);
    });
});

describe('ObjectAreaController', function () {
    beforeEach(function () {
        $this->area = ObjectArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'object_id' => $this->object->id,
            'name' => 'Test Area',
        ]);
    });

    test('user can list object areas', function () {
        // Act
        $response = getJson('/v1/object-areas');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    test('user can view object area', function () {
        // Act
        $response = getJson("/v1/object-areas/{$this->area->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $this->area->id)
            ->assertJsonPath('data.name', 'Test Area');
    });

    test('user can update object area', function () {
        // Arrange
        $data = ['name' => 'Updated Area Name'];

        // Act
        $response = patchJson("/v1/object-areas/{$this->area->id}", $data);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Area Name');
    });

    test('user can delete object area', function () {
        // Act
        $response = deleteJson("/v1/object-areas/{$this->area->id}");

        // Assert
        $response->assertNoContent();
        $this->assertSoftDeleted('object_areas', ['id' => $this->area->id]);
    });
});
