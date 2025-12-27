<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\CustomerAssignmentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property CustomerAssignmentPolicy $policy
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

    $this->policy = new CustomerAssignmentPolicy;
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('user assigned to customer can view its assignments', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
    ]);

    expect($this->policy->viewAny($user, $customer))->toBeTrue();
});

test('user with permission and customer update access can create assignments', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'assignments.create');
    $customer = Customer::factory()->for($this->tenant, 'tenant')->create();

    CustomerAssignment::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
    ]);

    expect($this->policy->create($user, $customer))->toBeTrue();
});
