<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\CostCenterPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property CostCenterPolicy $policy
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

    $this->policy = new CostCenterPolicy;
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('users with cost-centers.read permission can view any cost centers', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'cost-centers.read');

    expect($this->policy->viewAny($user))->toBeTrue();
});

test('user assigned to site can view its cost centers', function (): void {
    $user = User::factory()->create();
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($customer)->create();
    $costCenter = CostCenter::factory()->for($site)->create();

    SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'site_id' => $site->id,
    ]);

    expect($this->policy->view($user, $costCenter))->toBeTrue();
});

test('user with permission and site update access can create cost centers', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'cost-centers.create');
    $orgUnit = OrganizationalUnit::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();
    $site = Site::factory()->for($customer)->create();

    SiteAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'site_id' => $site->id,
    ]);

    expect($this->policy->create($user, $site))->toBeTrue();
});
