<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
 * @covers \App\Models\User::hasAccessibleCustomers
 * @covers \App\Models\User::hasAccessibleSites
 */
uses(RefreshDatabase::class)->group('unit', 'models', 'user', 'access');

/**
 * @property TenantKey $tenant
 * @property User $user
 */
beforeEach(function () {
    incrementTestKekCounter();
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
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('customer assignments relationship', function () {
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $this->user->id,
        'customer_id' => $customer->id,
        'role' => 'Key Account',
    ]);

    $assignments = $this->user->customerAssignments;

    expect($assignments)->toHaveCount(1);
    expect($assignments->first()->customer_id)->toBe($customer->id);
    expect($assignments->first()->role)->toBe('Key Account');
});

test('site assignments relationship', function () {
    $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $this->user->id,
        'site_id' => $site->id,
        'role' => 'Site Manager',
    ]);

    $assignments = $this->user->siteAssignments;

    expect($assignments)->toHaveCount(1);
    expect($assignments->first()->site_id)->toBe($site->id);
    expect($assignments->first()->role)->toBe('Site Manager');
});

test('assigned customers relationship', function () {
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

    expect($customers)->toHaveCount(2);
    expect($customers->contains($customer1))->toBeTrue();
    expect($customers->contains($customer2))->toBeTrue();
    expect($customers->find($customer1->id)->pivot->role)->toBe('Key Account');
    expect($customers->find($customer2->id)->pivot->role)->toBe('Support Contact');
});

test('assigned sites relationship', function () {
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

    expect($sites)->toHaveCount(2);
    expect($sites->contains($site1))->toBeTrue();
    expect($sites->contains($site2))->toBeTrue();
    expect($sites->find($site1->id)->pivot->role)->toBe('Site Manager');
    expect($sites->find($site2->id)->pivot->role)->toBe('Site Coordinator');
});

test('get accessible organizational unit ids returns empty array when no scopes', function () {
    $unitIds = $this->user->getAccessibleOrganizationalUnitIds();

    expect($unitIds)->toBeArray();
    $this->assertEmpty($unitIds);
});

test('get accessible organizational unit ids returns directly scoped units', function () {
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

    expect($unitIds)->toHaveCount(2);
    expect($unitIds)->toContain($orgUnit1->id);
    expect($unitIds)->toContain($orgUnit2->id);
});

test('get accessible customers returns empty when no access', function () {
    $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
    $unassignedCustomer = Customer::factory()->for($this->tenant, 'tenant')->create();
    Site::factory()->for($this->tenant, 'tenant')->for($unassignedCustomer)->for($orgUnit, 'organizationalUnit')->create();

    $customers = $this->user->getAccessibleCustomers();

    expect($customers)->toHaveCount(0);
});

test('has accessible customers returns false when no access exists', function () {
    $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
    $unassignedCustomer = Customer::factory()->for($this->tenant, 'tenant')->create();
    Site::factory()->for($this->tenant, 'tenant')->for($unassignedCustomer)->for($orgUnit, 'organizationalUnit')->create();

    expect($this->user->hasAccessibleCustomers())->toBeFalse();
});

test('get accessible customers includes directly assigned customers', function () {
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

    expect($customers)->toHaveCount(2);
    expect($customers->contains($customer1))->toBeTrue();
    expect($customers->contains($customer2))->toBeTrue();
    expect($customers->contains($customer3))->toBeFalse();
});

test('has accessible customers returns true for direct customer assignments', function () {
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $this->user->id,
        'customer_id' => $customer->id,
    ]);

    expect($this->user->hasAccessibleCustomers())->toBeTrue();
});

test('get accessible customers includes customers with sites in accessible org units', function () {
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

    expect($customers)->toHaveCount(1);
    expect($customers->contains($customer))->toBeTrue();
});

test('get accessible customers includes customers with directly assigned sites', function () {
    $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $this->user->id,
        'site_id' => $site->id,
    ]);

    $customers = $this->user->getAccessibleCustomers();

    expect($customers)->toHaveCount(1);
    expect($customers->contains($customer))->toBeTrue();
});

test('get accessible customers combines all access paths', function () {
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

    expect($customers)->toHaveCount(3);
    expect($customers->contains($customer1))->toBeTrue();
    expect($customers->contains($customer2))->toBeTrue();
    expect($customers->contains($customer3))->toBeTrue();
    expect($customers->contains($customer4))->toBeFalse();
});

test('get accessible sites returns empty when no access', function () {
    $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    $sites = $this->user->getAccessibleSites();

    expect($sites)->toHaveCount(0);
});

test('has accessible sites returns false when no access exists', function () {
    $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    expect($this->user->hasAccessibleSites())->toBeFalse();
});

test('get accessible sites includes sites in accessible org units', function () {
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

    expect($sites)->toHaveCount(1);
    expect($sites->contains($site1))->toBeTrue();
    expect($sites->contains($site2))->toBeFalse();
});

test('get accessible sites includes directly assigned sites', function () {
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

    expect($sites)->toHaveCount(2);
    expect($sites->contains($site1))->toBeTrue();
    expect($sites->contains($site2))->toBeTrue();
    expect($sites->contains($site3))->toBeFalse();
});

test('get accessible sites includes sites from assigned customers', function () {
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

    expect($sites)->toHaveCount(2);
    expect($sites->contains($site1))->toBeTrue();
    expect($sites->contains($site2))->toBeTrue();
});

test('has accessible sites returns true for customer assignments', function () {
    $orgUnit = OrganizationalUnit::factory()->for($this->tenant, 'tenant')->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    Site::factory()->for($this->tenant, 'tenant')->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $this->user->id,
        'customer_id' => $customer->id,
    ]);

    expect($this->user->hasAccessibleSites())->toBeTrue();
});

test('get accessible sites combines all access paths', function () {
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

    expect($sites)->toHaveCount(3);
    expect($sites->contains($site1))->toBeTrue();
    expect($sites->contains($site2))->toBeTrue();
    expect($sites->contains($site3))->toBeTrue();
    expect($sites->contains($site4))->toBeFalse();
});

test('get accessible customers does not include duplicates', function () {
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

    expect($customers)->toHaveCount(1);
    expect($customers->contains($customer))->toBeTrue();
});

test('get accessible sites does not include duplicates', function () {
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

    expect($sites)->toHaveCount(1);
    expect($sites->contains($site))->toBeTrue();
});
