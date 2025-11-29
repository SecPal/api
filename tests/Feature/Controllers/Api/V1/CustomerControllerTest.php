<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Customer;
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

    // Create organizational unit for managed_by relationship
    $this->orgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Sales Department',
    ]);

    // Give user admin scope on the org unit
    UserInternalOrganizationalScope::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'admin',
    ]);

    // Create a root customer for testing
    $this->rootCustomer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Acme Corp',
        'customer_number' => 'CUST-001',
        'type' => 'corporate',
        'managed_by_organizational_unit_id' => $this->orgUnit->id,
    ]);
});

afterEach(function () {
    // Reset tenant context
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('CustomerController - List', function () {
    test('user can list customers', function () {
        // Arrange
        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_number' => 'CUST-002',
            'managed_by_organizational_unit_id' => $this->orgUnit->id,
        ]);

        // Act
        $response = getJson('/v1/customers');

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'customer_number', 'type', 'created_at', 'updated_at'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonCount(2, 'data');
    });

    test('list customers can filter by type', function () {
        // Arrange
        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_number' => 'CUST-003',
            'type' => 'regional',
            'managed_by_organizational_unit_id' => $this->orgUnit->id,
        ]);

        // Act
        $response = getJson('/v1/customers?type=corporate');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'corporate');
    });

    test('list customers can filter by managed_by', function () {
        // Arrange: Create another org unit and customer
        $otherOrgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_number' => 'CUST-004',
            'managed_by_organizational_unit_id' => $otherOrgUnit->id,
        ]);

        // Act
        $response = getJson("/v1/customers?managed_by={$this->orgUnit->id}");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->rootCustomer->id);
    });
});

describe('CustomerController - Create', function () {
    test('user can create customer', function () {
        // Arrange
        $data = [
            'name' => 'New Customer Inc',
            'customer_number' => 'CUST-NEW',
            'type' => 'corporate',
            'address' => '123 Main St',
            'contact_email' => 'contact@newcustomer.com',
        ];

        // Act
        $response = postJson('/v1/customers', $data);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Customer Inc')
            ->assertJsonPath('data.customer_number', 'CUST-NEW');

        $this->assertDatabaseHas('customers', [
            'name' => 'New Customer Inc',
            'customer_number' => 'CUST-NEW',
            'tenant_id' => $this->tenant->id,
        ]);
    });

    test('user can create customer with parent', function () {
        // Arrange
        $data = [
            'name' => 'Regional Inc',
            'customer_number' => 'CUST-SUB',
            'type' => 'regional',
            'parent_id' => $this->rootCustomer->id,
        ];

        // Act
        $response = postJson('/v1/customers', $data);

        // Assert
        $response->assertCreated();

        // Verify closure table entry
        $this->assertDatabaseHas('customer_closures', [
            'ancestor_id' => $this->rootCustomer->id,
            'descendant_id' => Customer::where('customer_number', 'CUST-SUB')->first()->id,
            'depth' => 1,
        ]);
    });

    test('create customer requires name and customer_number', function () {
        // Arrange
        $data = ['type' => 'corporate'];

        // Act
        $response = postJson('/v1/customers', $data);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'customer_number']);
    });

    test('create customer requires unique customer_number within tenant', function () {
        // Arrange
        $data = [
            'name' => 'Another Corp',
            'customer_number' => 'CUST-001', // Already exists
            'type' => 'corporate',
        ];

        // Act
        $response = postJson('/v1/customers', $data);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_number']);
    });
});

describe('CustomerController - Show', function () {
    test('user can view customer', function () {
        // Act
        $response = getJson("/v1/customers/{$this->rootCustomer->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $this->rootCustomer->id)
            ->assertJsonPath('data.name', 'Acme Corp');
    });
});

describe('CustomerController - Update', function () {
    test('user can update customer', function () {
        // Arrange
        $data = [
            'name' => 'Acme Corporation Updated',
            'contact_email' => 'updated@acme.com',
        ];

        // Act
        $response = patchJson("/v1/customers/{$this->rootCustomer->id}", $data);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.name', 'Acme Corporation Updated')
            ->assertJsonPath('data.contact_email', 'updated@acme.com');
    });
});

describe('CustomerController - Delete', function () {
    test('user can delete customer', function () {
        // Arrange
        $customerToDelete = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_number' => 'CUST-DEL',
            'managed_by_organizational_unit_id' => $this->orgUnit->id,
        ]);

        // Act
        $response = deleteJson("/v1/customers/{$customerToDelete->id}");

        // Assert
        $response->assertNoContent();
        $this->assertSoftDeleted('customers', ['id' => $customerToDelete->id]);
    });
});

describe('CustomerController - Hierarchy', function () {
    test('user can get descendants of customer', function () {
        // Arrange: Create hierarchy
        $child = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_number' => 'CUST-CHILD',
            'managed_by_organizational_unit_id' => $this->orgUnit->id,
        ]);
        $child->setParent($this->rootCustomer);

        // Act
        $response = getJson("/v1/customers/{$this->rootCustomer->id}/descendants");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $child->id);
    });

    test('user can get ancestors of customer', function () {
        // Arrange
        $child = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_number' => 'CUST-CHILD2',
            'managed_by_organizational_unit_id' => $this->orgUnit->id,
        ]);
        $child->setParent($this->rootCustomer);

        // Act
        $response = getJson("/v1/customers/{$child->id}/ancestors");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->rootCustomer->id);
    });

    test('user can attach parent to customer', function () {
        // Arrange
        $orphan = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_number' => 'CUST-ORPHAN',
            'managed_by_organizational_unit_id' => $this->orgUnit->id,
        ]);

        // Act
        $response = postJson("/v1/customers/{$orphan->id}/parent", [
            'parent_id' => $this->rootCustomer->id,
        ]);

        // Assert
        $response->assertOk();

        $this->assertDatabaseHas('customer_closures', [
            'ancestor_id' => $this->rootCustomer->id,
            'descendant_id' => $orphan->id,
            'depth' => 1,
        ]);
    });
});
