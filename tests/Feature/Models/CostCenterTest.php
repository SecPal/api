<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property Customer $customer
 * @property OrganizationalUnit $organizationalUnit
 * @property Site $site
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::ensureKekExists();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->organizationalUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
    ]);
});

test('cost center has required relationships', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
    ]);

    expect($costCenter->tenant)->toBeInstanceOf(TenantKey::class)
        ->and($costCenter->site)->toBeInstanceOf(Site::class);
});

test('cost center belongs to tenant', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
    ]);

    expect($costCenter->tenant->id)->toBe($this->tenant->id);
});

test('cost center belongs to site', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
    ]);

    expect($costCenter->site->id)->toBe($this->site->id);
});

test('cost center has code field', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'code' => 'KST-001',
    ]);

    expect($costCenter->code)->toBe('KST-001');
});

test('cost center has name field', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'name' => 'Reception Duty',
    ]);

    expect($costCenter->name)->toBe('Reception Duty');
});

test('cost center can have optional activity type', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'activity_type' => 'Security Guard',
    ]);

    expect($costCenter->activity_type)->toBe('Security Guard');
});

test('cost center activity type can be null', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'activity_type' => null,
    ]);

    expect($costCenter->activity_type)->toBeNull();
});

test('cost center can have optional description', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'description' => 'This is a description',
    ]);

    expect($costCenter->description)->toBe('This is a description');
});

test('cost center description can be null', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'description' => null,
    ]);

    expect($costCenter->description)->toBeNull();
});

test('cost center is active by default', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
    ]);

    expect($costCenter->is_active)->toBeTrue();
});

test('cost center can be inactive', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'is_active' => false,
    ]);

    expect($costCenter->is_active)->toBeFalse();
});

test('active scope filters active cost centers', function (): void {
    $active = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'is_active' => true,
    ]);

    $inactive = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'is_active' => false,
    ]);

    $activeCostCenters = CostCenter::active()
        ->whereIn('id', [$active->id, $inactive->id])
        ->get();

    expect($activeCostCenters)->toHaveCount(1)
        ->and($activeCostCenters->first()->is_active)->toBeTrue();
});

test('forTenant scope filters cost centers by tenant', function (): void {
    $keys2 = TenantKey::generateEnvelopeKeys();
    $tenant2 = TenantKey::create($keys2);
    $customer2 = Customer::factory()->create(['tenant_id' => $tenant2->id]);
    $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $tenant2->id]);
    $site2 = Site::factory()->create([
        'tenant_id' => $tenant2->id,
        'customer_id' => $customer2->id,
    ]);

    CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
    ]);

    CostCenter::factory()->create([
        'tenant_id' => $tenant2->id,
        'site_id' => $site2->id,
    ]);

    $costCenters = CostCenter::forTenant($this->tenant->id)->get();

    expect($costCenters)->toHaveCount(1)
        ->and($costCenters->first()->tenant_id)->toBe($this->tenant->id);
});

test('forActivityType scope filters cost centers by activity type', function (): void {
    $securityGuard = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'activity_type' => 'Security Guard',
    ]);

    $reception = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'activity_type' => 'Reception',
    ]);

    $costCenters = CostCenter::forActivityType('Security Guard')
        ->whereIn('id', [$securityGuard->id, $reception->id])
        ->get();

    expect($costCenters)->toHaveCount(1)
        ->and($costCenters->first()->activity_type)->toBe('Security Guard');
});

test('forSite scope filters cost centers by site', function (): void {
    $site2 = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'site_number' => 'OBJ-TEST-'.time(),
    ]);

    $cc1 = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
    ]);

    $cc2 = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $site2->id,
    ]);

    $costCenters = CostCenter::forSite($this->site->id)
        ->whereIn('id', [$cc1->id, $cc2->id])
        ->get();

    expect($costCenters)->toHaveCount(1)
        ->and($costCenters->first()->site_id)->toBe($this->site->id);
});

test('cost center uses soft deletes', function (): void {
    $costCenter = CostCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
    ]);

    $id = $costCenter->id;
    $costCenter->delete();

    expect($costCenter->trashed())->toBeTrue()
        ->and(CostCenter::where('id', $id)->count())->toBe(0)
        ->and(CostCenter::withTrashed()->where('id', $id)->count())->toBe(1);
});

test('site can have multiple cost centers', function (): void {
    CostCenter::factory()->count(3)->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
    ]);

    $costCenters = CostCenter::forSite($this->site->id)->get();

    expect($costCenters)->toHaveCount(3);
});
