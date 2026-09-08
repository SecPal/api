<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    $establishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->customer->legal_entity_id,
    ]);
    $this->link = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->customer->legal_entity_id,
        'customer_id' => $this->customer->id,
        'establishment_id' => $establishment->id,
    ]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('assignment-based customer access does not authorize customer establishment mutations', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $user->id,
    ]);

    expect($user->can('update', $this->link))->toBeFalse()
        ->and($user->can('delete', $this->link))->toBeFalse();
});

test('customers.update authorizes same-tenant customer establishment mutations', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'customers.update');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

    expect($user->can('update', $this->link))->toBeTrue()
        ->and($user->can('delete', $this->link))->toBeTrue();
});

test('customers.update does not override the organizational scope restriction', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'customers.update');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    $organizationalUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $organizationalUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    expect($user->can('update', $this->link))->toBeFalse()
        ->and($user->can('delete', $this->link))->toBeFalse();
});

test("customers.update does not authorize another tenant's customer establishment", function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'customers.update');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $otherCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherEstablishment = Establishment::factory()->create([
        'tenant_id' => $otherTenant->id,
        'legal_entity_id' => $otherCustomer->legal_entity_id,
    ]);
    $otherLink = CustomerEstablishment::factory()->create([
        'tenant_id' => $otherTenant->id,
        'legal_entity_id' => $otherCustomer->legal_entity_id,
        'customer_id' => $otherCustomer->id,
        'establishment_id' => $otherEstablishment->id,
    ]);

    expect($user->can('update', $otherLink))->toBeFalse()
        ->and($user->can('delete', $otherLink))->toBeFalse();
});
