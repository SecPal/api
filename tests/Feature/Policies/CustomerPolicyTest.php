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
use App\Policies\CustomerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property CustomerPolicy $policy
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->policy = new CustomerPolicy;
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

// viewAny tests
test('users with customers.read permission can view any customers', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'customers.read');

    expect($this->policy->viewAny($user))->toBeTrue();
});

test('users without customers.read permission can still call viewAny', function (): void {
    $user = User::factory()->create();

    // viewAny always returns true - Need-to-Know filtering happens in controller
    expect($this->policy->viewAny($user))->toBeTrue();
});

// view tests - direct assignment
test('user assigned to customer can view it', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
    ]);

    expect($this->policy->view($user, $customer))->toBeTrue();
});

test('user not assigned to customer cannot view it', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->view($user, $customer))->toBeFalse();
});

// view tests - site access
test('user with access to customer site can view customer', function (): void {
    $user = User::factory()->create();
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    // Give user access to organizational unit
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    expect($this->policy->view($user, $customer))->toBeTrue();
});

test('user assigned to site can view customer', function (): void {
    $user = User::factory()->create();
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'site_id' => $site->id,
    ]);

    expect($this->policy->view($user, $customer))->toBeTrue();
});

test('user without any access cannot view customer', function (): void {
    $user = User::factory()->create();
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    Site::factory()->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    expect($this->policy->view($user, $customer))->toBeFalse();
});

// create tests
test('users with customers.create permission can create customers', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'customers.create');

    expect($this->policy->create($user))->toBeTrue();
});

test('users without customers.create permission cannot create customers', function (): void {
    $user = User::factory()->create();

    expect($this->policy->create($user))->toBeFalse();
});

// update tests
test('user assigned to customer can update it', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
    ]);

    expect($this->policy->update($user, $customer))->toBeTrue();
});

test('user with customers.update permission can update any customer', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'customers.update');
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->update($user, $customer))->toBeTrue();
});

test('user without assignment or permission cannot update customer', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->update($user, $customer))->toBeFalse();
});

// delete tests
test('user with customers.delete permission can delete customer without active sites', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'customers.delete');
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->delete($user, $customer))->toBeTrue();
});

test('user with permission can delete customer (active sites check in controller)', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'customers.delete');
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    Site::factory()->for($customer)->for($orgUnit, 'organizationalUnit')->create(['is_active' => true]);

    // Policy only checks permission - business rule (active sites) handled in controller
    expect($this->policy->delete($user, $customer))->toBeTrue();
});

test('user without customers.delete permission cannot delete customer', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->delete($user, $customer))->toBeFalse();
});

// Temporal validation tests
test('user with expired customer assignment cannot view customer', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'valid_from' => now()->subDays(30),
        'valid_until' => now()->subDay(), // expired yesterday
    ]);

    expect($this->policy->view($user, $customer))->toBeFalse();
});

test('user with future customer assignment cannot view customer yet', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'valid_from' => now()->addDay(), // starts tomorrow
        'valid_until' => now()->addDays(30),
    ]);

    expect($this->policy->view($user, $customer))->toBeFalse();
});

test('user with expired customer assignment cannot update customer', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'valid_from' => now()->subDays(30),
        'valid_until' => now()->subDay(),
    ]);

    expect($this->policy->update($user, $customer))->toBeFalse();
});

test('user with expired site assignment cannot view customer via site', function (): void {
    $user = User::factory()->create();
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'site_id' => $site->id,
        'valid_from' => now()->subDays(30),
        'valid_until' => now()->subDay(),
    ]);

    expect($this->policy->view($user, $customer))->toBeFalse();
});
