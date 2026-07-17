<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Customer;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant1
 * @property TenantKey $tenant2
 * @property User $user1
 * @property mixed $token1
 * @property User $user2
 * @property mixed $token2
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Use process-specific KEK file
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create Tenant 1
    $keys1 = TenantKey::generateEnvelopeKeys();
    $this->tenant1 = TenantKey::create($keys1);

    // Create Tenant 2
    $keys2 = TenantKey::generateEnvelopeKeys();
    $this->tenant2 = TenantKey::create($keys2);

    // Set tenant context for Tenant 1
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant1->id);

    // Seed permissions
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    // Create users for Tenant 1
    $this->user1 = User::factory()->create(['tenant_id' => $this->tenant1->id]);
    $this->token1 = $this->user1->createToken('test-device')->plainTextToken;

    // Create users for Tenant 2
    $this->user2 = User::factory()->create(['tenant_id' => $this->tenant2->id]);
    $this->token2 = $this->user2->createToken('test-device')->plainTextToken;

    // Give both users full permissions in their respective tenants
    $permissions = [
        'sites.read', 'sites.create', 'sites.update', 'sites.delete',
        'customers.read', 'customers.create', 'customers.update', 'customers.delete',
        'employee.read', 'employee.create', 'employee.update', 'employee.delete',
        'employee.activate', 'employee.terminate',
    ];

    foreach ($permissions as $permission) {
        givePermissionWithTenant($this->user1, $this->tenant1->id, $permission);
    }

    foreach ($permissions as $permission) {
        givePermissionWithTenant($this->user2, $this->tenant2->id, $permission);
    }
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('Cross-Tenant Isolation - Sites', function () {
    test('user cannot list sites from other tenant', function () {
        // Create org units and customers for both tenants
        $customer1 = Customer::factory()->create(['tenant_id' => $this->tenant1->id]);
        $establishment = App\Models\Establishment::factory()->create([
            'tenant_id' => $this->tenant1->id,
            'legal_entity_id' => $customer1->legal_entity_id,
        ]);
        App\Models\CustomerEstablishment::factory()->create([
            'tenant_id' => $this->tenant1->id,
            'legal_entity_id' => $customer1->legal_entity_id,
            'customer_id' => $customer1->id,
            'establishment_id' => $establishment->id,
        ]);

        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant2->id]);

        // Create sites for both tenants
        $site1 = Site::factory()->create([
            'tenant_id' => $this->tenant1->id,
            'customer_id' => $customer1->id,
            'name' => 'Tenant 1 Site',
        ]);

        $site2 = Site::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'customer_id' => $customer2->id,
            'name' => 'Tenant 2 Site',
        ]);

        // User1 should only see Tenant1 site
        $response = $this->withToken($this->token1)->getJson('/v1/sites');

        $response->assertOk();
        $siteIds = collect($response->json('data'))->pluck('id')->toArray();

        expect($siteIds)->toContain($site1->id);
        expect($siteIds)->not->toContain($site2->id);
    });

    test('user cannot view site from other tenant by ID', function () {
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant2->id]);

        $site2 = Site::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'customer_id' => $customer2->id,
        ]);

        // User1 attempts to view Tenant2 site
        $response = $this->withToken($this->token1)->getJson("/v1/sites/{$site2->id}");

        $response->assertStatus(404); // Fail closed for foreign-tenant resources
    });

    test('user cannot create site with references to other tenant', function () {
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant2->id]);

        // User1 attempts to create site using Tenant2 resources
        $response = $this->withToken($this->token1)->postJson('/v1/sites', [
            'name' => 'Cross-Tenant Attack',
            'type' => 'permanent',
            'customer_id' => $customer2->id, // Different tenant!
            'organizational_unit_id' => $orgUnit2->id, // Different tenant!
            'address' => [
                'street' => 'Test Street 1',
                'city' => 'Test City',
                'postal_code' => '12345',
                'country' => 'DE',
            ],
        ]);

        // Should fail validation (customer/orgUnit don't exist in user1's tenant)
        $response->assertStatus(422);
    });

    test('user cannot update site from other tenant', function () {
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant2->id]);

        $site2 = Site::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'customer_id' => $customer2->id,
        ]);

        // User1 attempts to update Tenant2 site
        $response = $this->withToken($this->token1)->patchJson("/v1/sites/{$site2->id}", [
            'name' => 'Hacked Name',
        ]);

        $response->assertStatus(404); // Fail closed for foreign-tenant resources
    });

    test('user cannot delete site from other tenant', function () {
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant2->id]);

        $site2 = Site::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'customer_id' => $customer2->id,
        ]);

        // User1 attempts to delete Tenant2 site
        $response = $this->withToken($this->token1)->deleteJson("/v1/sites/{$site2->id}");

        $response->assertStatus(404); // Fail closed for foreign-tenant resources

        // Verify site still exists
        expect(Site::find($site2->id))->not->toBeNull();
    });
});

describe('Cross-Tenant Isolation - Customers', function () {
    test('user cannot list customers from other tenant', function () {
        $customer1 = Customer::factory()->create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'Tenant 1 Customer',
        ]);

        $customer2 = Customer::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'name' => 'Tenant 2 Customer',
        ]);

        // User1 should only see Tenant1 customer
        $response = $this->withToken($this->token1)->getJson('/v1/customers');

        $response->assertOk();
        $customerIds = collect($response->json('data'))->pluck('id')->toArray();

        expect($customerIds)->toContain($customer1->id);
        expect($customerIds)->not->toContain($customer2->id);
    });

    test('user cannot view customer from other tenant by ID', function () {
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant2->id]);

        $response = $this->withToken($this->token1)->getJson("/v1/customers/{$customer2->id}");

        $response->assertStatus(404); // Fail closed for foreign-tenant resources
    });

    test('user cannot update customer from other tenant', function () {
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant2->id]);

        $response = $this->withToken($this->token1)->patchJson("/v1/customers/{$customer2->id}", [
            'name' => 'Hacked Name',
        ]);

        $response->assertStatus(404); // Fail closed for foreign-tenant resources
    });

    test('user cannot delete customer from other tenant', function () {
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant2->id]);

        $response = $this->withToken($this->token1)->deleteJson("/v1/customers/{$customer2->id}");

        $response->assertStatus(404); // Fail closed for foreign-tenant resources
        expect(Customer::find($customer2->id))->not->toBeNull();
    });
});

describe('Cross-Tenant Isolation - Employees', function () {
    test('user cannot list employees from other tenant', function () {
        $orgUnit1 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant1->id]);
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);

        $employee1 = Employee::factory()->create([
            'tenant_id' => $this->tenant1->id,
        ]);

        $employee2 = Employee::factory()->create([
            'tenant_id' => $this->tenant2->id,
        ]);

        // User1 should only see Tenant1 employee
        $response = $this->withToken($this->token1)->getJson('/v1/employees');

        $response->assertOk();
        $employeeIds = collect($response->json('data'))->pluck('id')->toArray();

        expect($employeeIds)->toContain($employee1->id);
        expect($employeeIds)->not->toContain($employee2->id);
    });

    test('user cannot view employee from other tenant by ID', function () {
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $employee2 = Employee::factory()->create([
            'tenant_id' => $this->tenant2->id,
        ]);

        $response = $this->withToken($this->token1)->getJson("/v1/employees/{$employee2->id}");

        $response->assertStatus(404); // Fail closed for foreign-tenant resources
    });

    test('user cannot update employee from other tenant', function () {
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $employee2 = Employee::factory()->create([
            'tenant_id' => $this->tenant2->id,
        ]);

        $response = $this->withToken($this->token1)->patchJson("/v1/employees/{$employee2->id}", [
            'first_name' => 'Hacked',
        ]);

        $response->assertStatus(404); // Fail closed for foreign-tenant resources
    });

    test('user cannot delete employee from other tenant', function () {
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $employee2 = Employee::factory()->create([
            'tenant_id' => $this->tenant2->id,
        ]);

        $response = $this->withToken($this->token1)->deleteJson("/v1/employees/{$employee2->id}");

        $response->assertStatus(404); // Fail closed for foreign-tenant resources
        expect(Employee::find($employee2->id))->not->toBeNull();
    });

    test('user cannot activate employee from other tenant', function () {
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $employee2 = Employee::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'status' => 'pre_contract',
        ]);

        $response = $this->withToken($this->token1)->postJson("/v1/employees/{$employee2->id}/activate");

        $response->assertStatus(404); // Fail closed for foreign-tenant resources

        // Verify status unchanged
        $employee2->refresh();
        expect($employee2->status)->toBe('pre_contract');
    });

    test('user cannot terminate employee from other tenant', function () {
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $employee2 = Employee::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'status' => 'active',
        ]);

        $response = $this->withToken($this->token1)->postJson("/v1/employees/{$employee2->id}/terminate");

        $response->assertStatus(404); // Fail closed for foreign-tenant resources

        // Verify status unchanged
        $employee2->refresh();
        expect($employee2->status)->toBe('active');
    });
});

// Note: Organizational Units use scope-based access control, not permission-based
// Tenant isolation is enforced at the Policy level (viewAny returns empty for other tenants)

describe('Query String Spoofing Prevention', function () {
    test('query string tenant_id is ignored for sites', function () {
        $orgUnit1 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant1->id]);
        $customer1 = Customer::factory()->create(['tenant_id' => $this->tenant1->id]);
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant2->id]);

        Site::factory()->create([
            'tenant_id' => $this->tenant1->id,
            'customer_id' => $customer1->id,
            'name' => 'Tenant 1 Site',
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'customer_id' => $customer2->id,
            'name' => 'Tenant 2 Site',
        ]);

        // Attempt to spoof tenant_id via query string
        $response = $this->withToken($this->token1)->getJson("/v1/sites?tenant_id={$this->tenant2->id}");

        $response->assertOk();
        // Should only see Tenant 1 sites
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['name'])->toBe('Tenant 1 Site');
    });

    test('request body tenant_id is ignored for site creation', function () {
        $customer1 = Customer::factory()->create(['tenant_id' => $this->tenant1->id]);
        $establishment = App\Models\Establishment::factory()->create([
            'tenant_id' => $this->tenant1->id,
            'legal_entity_id' => $customer1->legal_entity_id,
        ]);
        App\Models\CustomerEstablishment::query()->create([
            'tenant_id' => $this->tenant1->id,
            'legal_entity_id' => $customer1->legal_entity_id,
            'customer_id' => $customer1->id,
            'establishment_id' => $establishment->id,
        ]);

        $response = $this->withToken($this->token1)->postJson('/v1/sites', [
            'name' => 'Test Site',
            'type' => 'permanent',
            'tenant_id' => $this->tenant2->id, // Spoofed tenant_id
            'customer_id' => $customer1->id,
            'legal_entity_id' => $customer1->legal_entity_id,
            'establishment_id' => $establishment->id,
            'address' => [
                'street' => 'Test Street',
                'city' => 'Test City',
                'postal_code' => '12345',
                'country' => 'DE',
            ],
        ]);

        $response->assertStatus(201);

        // Verify site was created in user's tenant (tenant1), not spoofed tenant
        $site = Site::latest()->first();
        expect($site->tenant_id)->toBe($this->tenant1->id);
        expect($site->tenant_id)->not->toBe($this->tenant2->id);
    });
});

describe('Security Attack Scenarios', function () {
    test('cannot access resource by guessing IDs from other tenant', function () {
        // Create 100 sites in Tenant2
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant2->id]);

        $sites2 = Site::factory()->count(100)->create([
            'tenant_id' => $this->tenant2->id,
            'customer_id' => $customer2->id,
        ]);

        // User1 attempts to access each Tenant2 site
        foreach ($sites2 as $site) {
            $response = $this->withToken($this->token1)->getJson("/v1/sites/{$site->id}");
            $response->assertStatus(404); // All should fail closed for foreign-tenant resources
        }
    });

    test('cannot update tenant_id of own resource to move to other tenant', function () {
        $orgUnit1 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant1->id]);
        $customer1 = Customer::factory()->create(['tenant_id' => $this->tenant1->id]);

        $site1 = Site::factory()->create([
            'tenant_id' => $this->tenant1->id,
            'customer_id' => $customer1->id,
        ]);

        // Attempt to change site's tenant_id
        $response = $this->withToken($this->token1)->patchJson("/v1/sites/{$site1->id}", [
            'tenant_id' => $this->tenant2->id,
        ]);

        // Even if update succeeds, tenant_id should NOT change
        $site1->refresh();
        expect($site1->tenant_id)->toBe($this->tenant1->id);
    });

    test('cannot use bulk operations to access other tenant data', function () {
        // Create sites in both tenants
        $orgUnit1 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant1->id]);
        $customer1 = Customer::factory()->create(['tenant_id' => $this->tenant1->id]);
        $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant2->id]);
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant2->id]);

        $site1 = Site::factory()->create([
            'tenant_id' => $this->tenant1->id,
            'customer_id' => $customer1->id,
        ]);

        $site2 = Site::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'customer_id' => $customer2->id,
        ]);

        // Attempt to filter by both tenants
        $response = $this->withToken($this->token1)->getJson("/v1/sites?ids[]={$site1->id}&ids[]={$site2->id}");

        $response->assertOk();
        // Should only return site1
        $siteIds = collect($response->json('data'))->pluck('id')->toArray();
        expect($siteIds)->toContain($site1->id);
        expect($siteIds)->not->toContain($site2->id);
    });
});
