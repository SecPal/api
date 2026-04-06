<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\SitePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property SitePolicy $policy
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->policy = new SitePolicy;
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('users with sites.read permission can view any sites', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'sites.read');

    expect($this->policy->viewAny($user))->toBeTrue();
});

test('users without sites.read permission and without scoped access cannot view any sites', function (): void {
    $user = User::factory()->create();

    expect($this->policy->viewAny($user))->toBeFalse();
});

test('users with customer assignments can view any sites', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    App\Models\CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
    ]);

    expect($this->policy->viewAny($user))->toBeTrue();
});

test('user assigned to site can view it', function (): void {
    $user = User::factory()->create();
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'site_id' => $site->id,
    ]);

    expect($this->policy->view($user, $site))->toBeTrue();
});

test('user with org unit access can view sites in that unit', function (): void {
    $user = User::factory()->create();
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    expect($this->policy->view($user, $site))->toBeTrue();
});

test('user with sites.delete permission can delete site without active cost centers', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'sites.delete');
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    expect($this->policy->delete($user, $site))->toBeTrue();
});

test('user cannot delete site with active cost centers', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'sites.delete');
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($customer)->for($orgUnit, 'organizationalUnit')->create();
    CostCenter::factory()->for($site)->create(['is_active' => true]);

    expect($this->policy->delete($user, $site))->toBeFalse();
});

// Temporal validation tests
test('user with expired site assignment cannot view site', function (): void {
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

    expect($this->policy->view($user, $site))->toBeFalse();
});

test('user with future site assignment cannot view site yet', function (): void {
    $user = User::factory()->create();
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($customer)->for($orgUnit, 'organizationalUnit')->create();

    SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'site_id' => $site->id,
        'valid_from' => now()->addDay(),
        'valid_until' => now()->addDays(30),
    ]);

    expect($this->policy->view($user, $site))->toBeFalse();
});

test('user with expired site assignment cannot update site', function (): void {
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

    expect($this->policy->update($user, $site))->toBeFalse();
});
