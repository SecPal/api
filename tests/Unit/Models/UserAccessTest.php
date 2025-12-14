<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit\Models;

use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for User model access methods (Epic #210).
 *
 * @covers \App\Models\User::customerAssignments
 * @covers \App\Models\User::siteAssignments
 * @covers \App\Models\User::assignedCustomers
 * @covers \App\Models\User::assignedSites
 * @covers \App\Models\User::getAccessibleOrganizationalUnitIds
 * @covers \App\Models\User::getAccessibleCustomers
 * @covers \App\Models\User::getAccessibleSites
 */
class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    protected TenantKey $tenant;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        TenantKey::setKekPath(getTestKekPath());

        if (! file_exists(TenantKey::getKekPath())) {
            TenantKey::generateKek();
        }

        if (! TenantKey::first()) {
            $keys = TenantKey::generateEnvelopeKeys();
            $this->tenant = TenantKey::create($keys);
        } else {
            $this->tenant = TenantKey::first();
        }

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        cleanupTestKekFile();
        TenantKey::setKekPath(null);
        parent::tearDown();
    }

    public function test_customer_assignments_relationship(): void
    {
        $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
        CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'role' => 'Key Account',
        ]);

        $assignments = $this->user->customerAssignments;

        $this->assertCount(1, $assignments);
        $this->assertEquals($customer->id, $assignments->first()->customer_id);
        $this->assertEquals('Key Account', $assignments->first()->role);
    }

    public function test_site_assignments_relationship(): void
    {
        $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
        $site = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

        SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'site_id' => $site->id,
            'role' => 'Site Manager',
        ]);

        $assignments = $this->user->siteAssignments;

        $this->assertCount(1, $assignments);
        $this->assertEquals($site->id, $assignments->first()->site_id);
        $this->assertEquals('Site Manager', $assignments->first()->role);
    }

    public function test_assigned_customers_relationship(): void
    {
        $customer1 = Customer::factory()->for($this->tenant, 'tenant')->create();
        $customer2 = Customer::factory()->for($this->tenant, 'tenant')->create();

        CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'customer_id' => $customer1->id,
            'role' => 'Key Account',
        ]);

        CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'customer_id' => $customer2->id,
            'role' => 'Support Contact',
        ]);

        $customers = $this->user->assignedCustomers;

        $this->assertCount(2, $customers);
        $this->assertTrue($customers->contains($customer1));
        $this->assertTrue($customers->contains($customer2));
        $this->assertEquals('Key Account', $customers->find($customer1->id)->pivot->role);
        $this->assertEquals('Support Contact', $customers->find($customer2->id)->pivot->role);
    }

    public function test_assigned_sites_relationship(): void
    {
        $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
        $site1 = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();
        $site2 = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

        SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'site_id' => $site1->id,
            'role' => 'Site Manager',
        ]);

        SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'site_id' => $site2->id,
            'role' => 'Site Coordinator',
        ]);

        $sites = $this->user->assignedSites;

        $this->assertCount(2, $sites);
        $this->assertTrue($sites->contains($site1));
        $this->assertTrue($sites->contains($site2));
        $this->assertEquals('Site Manager', $sites->find($site1->id)->pivot->role);
        $this->assertEquals('Site Coordinator', $sites->find($site2->id)->pivot->role);
    }

    public function test_get_accessible_organizational_unit_ids_returns_empty_array_when_no_scopes(): void
    {
        $unitIds = $this->user->getAccessibleOrganizationalUnitIds();

        $this->assertIsArray($unitIds);
        $this->assertEmpty($unitIds);
    }

    public function test_get_accessible_organizational_unit_ids_returns_directly_scoped_units(): void
    {
        $orgUnit1 = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $orgUnit2 = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit1->id,
            'access_level' => 'read',
            'include_descendants' => false,
        ]);

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit2->id,
            'access_level' => 'write',
            'include_descendants' => false,
        ]);

        $unitIds = $this->user->getAccessibleOrganizationalUnitIds();

        $this->assertCount(2, $unitIds);
        $this->assertContains($orgUnit1->id, $unitIds);
        $this->assertContains($orgUnit2->id, $unitIds);
    }

    public function test_get_accessible_customers_returns_empty_when_no_access(): void
    {
        $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $unassignedCustomer = Customer::factory()->for($this->tenant, 'tenant')->create();
        Site::factory()->for($this->tenant, 'tenant')->for($unassignedCustomer)->for($orgUnit, 'organizationalUnit')->create();

        $customers = $this->user->getAccessibleCustomers();

        $this->assertCount(0, $customers);
    }

    public function test_get_accessible_customers_includes_directly_assigned_customers(): void
    {
        $customer1 = Customer::factory()->for($this->tenant, 'tenant')->create();
        $customer2 = Customer::factory()->for($this->tenant, 'tenant')->create();
        $customer3 = Customer::factory()->for($this->tenant, 'tenant')->create();

        CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'customer_id' => $customer1->id,
        ]);

        CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'customer_id' => $customer2->id,
        ]);

        $customers = $this->user->getAccessibleCustomers();

        $this->assertCount(2, $customers);
        $this->assertTrue($customers->contains($customer1));
        $this->assertTrue($customers->contains($customer2));
        $this->assertFalse($customers->contains($customer3));
    }

    public function test_get_accessible_customers_includes_customers_with_sites_in_accessible_org_units(): void
    {
        $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
        Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'include_descendants' => false,
        ]);

        $customers = $this->user->getAccessibleCustomers();

        $this->assertCount(1, $customers);
        $this->assertTrue($customers->contains($customer));
    }

    public function test_get_accessible_customers_includes_customers_with_directly_assigned_sites(): void
    {
        $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
        $site = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

        SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'site_id' => $site->id,
        ]);

        $customers = $this->user->getAccessibleCustomers();

        $this->assertCount(1, $customers);
        $this->assertTrue($customers->contains($customer));
    }

    public function test_get_accessible_customers_combines_all_access_paths(): void
    {
        $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();

        // Customer 1: Direct assignment
        $customer1 = Customer::factory()->for($this->tenant, 'tenant')->create();
        CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'customer_id' => $customer1->id,
        ]);

        // Customer 2: Site in accessible org unit
        $customer2 = Customer::factory()->for($this->tenant, 'tenant')->create();
        Site::factory()->for($this->tenant, 'tenant')->for($customer2)->for($orgUnit, 'organizationalUnit')->create();

        // Customer 3: Direct site assignment
        $customer3 = Customer::factory()->for($this->tenant, 'tenant')->create();
        $site3 = Site::factory()->for($this->tenant, 'tenant')->for($customer3)->for($orgUnit, 'organizationalUnit')->create();
        SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'site_id' => $site3->id,
        ]);

        // Customer 4: No access
        $customer4 = Customer::factory()->for($this->tenant, 'tenant')->create();

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'include_descendants' => false,
        ]);

        $customers = $this->user->getAccessibleCustomers();

        $this->assertCount(3, $customers);
        $this->assertTrue($customers->contains($customer1));
        $this->assertTrue($customers->contains($customer2));
        $this->assertTrue($customers->contains($customer3));
        $this->assertFalse($customers->contains($customer4));
    }

    public function test_get_accessible_sites_returns_empty_when_no_access(): void
    {
        $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
        Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

        $sites = $this->user->getAccessibleSites();

        $this->assertCount(0, $sites);
    }

    public function test_get_accessible_sites_includes_sites_in_accessible_org_units(): void
    {
        $orgUnit1 = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $orgUnit2 = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

        $site1 = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit1, 'organizationalUnit')->create();
        $site2 = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit2, 'organizationalUnit')->create();

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit1->id,
            'access_level' => 'read',
            'include_descendants' => false,
        ]);

        $sites = $this->user->getAccessibleSites();

        $this->assertCount(1, $sites);
        $this->assertTrue($sites->contains($site1));
        $this->assertFalse($sites->contains($site2));
    }

    public function test_get_accessible_sites_includes_directly_assigned_sites(): void
    {
        $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

        $site1 = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();
        $site2 = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();
        $site3 = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

        SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'site_id' => $site1->id,
        ]);

        SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'site_id' => $site2->id,
        ]);

        $sites = $this->user->getAccessibleSites();

        $this->assertCount(2, $sites);
        $this->assertTrue($sites->contains($site1));
        $this->assertTrue($sites->contains($site2));
        $this->assertFalse($sites->contains($site3));
    }

    public function test_get_accessible_sites_includes_sites_from_assigned_customers(): void
    {
        $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

        $site1 = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();
        $site2 = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

        CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'role' => 'Key Account',
        ]);

        $sites = $this->user->getAccessibleSites();

        $this->assertCount(2, $sites);
        $this->assertTrue($sites->contains($site1));
        $this->assertTrue($sites->contains($site2));
    }

    public function test_get_accessible_sites_combines_all_access_paths(): void
    {
        $orgUnit1 = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $orgUnit2 = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $customer1 = Customer::factory()->for($this->tenant, 'tenant')->create();
        $customer2 = Customer::factory()->for($this->tenant, 'tenant')->create();

        // Site 1: Via org unit access
        $site1 = Site::factory()->for($this->tenant, 'tenant')->for($customer1)->for($orgUnit1, 'organizationalUnit')->create();

        // Site 2: Via direct site assignment
        $site2 = Site::factory()->for($this->tenant, 'tenant')->for($customer1)->for($orgUnit2, 'organizationalUnit')->create();
        SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'site_id' => $site2->id,
        ]);

        // Site 3: Via customer assignment (Key Account)
        $site3 = Site::factory()->for($this->tenant, 'tenant')->for($customer2)->for($orgUnit2, 'organizationalUnit')->create();
        CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'customer_id' => $customer2->id,
        ]);

        // Site 4: No access
        $site4 = Site::factory()->for($this->tenant, 'tenant')->for($customer1)->for($orgUnit2, 'organizationalUnit')->create();

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit1->id,
            'access_level' => 'read',
            'include_descendants' => false,
        ]);

        $sites = $this->user->getAccessibleSites();

        $this->assertCount(3, $sites);
        $this->assertTrue($sites->contains($site1));
        $this->assertTrue($sites->contains($site2));
        $this->assertTrue($sites->contains($site3));
        $this->assertFalse($sites->contains($site4));
    }

    public function test_get_accessible_customers_does_not_include_duplicates(): void
    {
        $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

        // Create multiple access paths to same customer
        CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
        ]);

        $site = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();
        SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'site_id' => $site->id,
        ]);

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'include_descendants' => false,
        ]);

        $customers = $this->user->getAccessibleCustomers();

        $this->assertCount(1, $customers);
        $this->assertTrue($customers->contains($customer));
    }

    public function test_get_accessible_sites_does_not_include_duplicates(): void
    {
        $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
        $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
        $site = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

        // Create multiple access paths to same site
        SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'site_id' => $site->id,
        ]);

        CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
        ]);

        UserInternalOrganizationalScope::create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'include_descendants' => false,
        ]);

        $sites = $this->user->getAccessibleSites();

        $this->assertCount(1, $sites);
        $this->assertTrue($sites->contains($site));
    }
}
