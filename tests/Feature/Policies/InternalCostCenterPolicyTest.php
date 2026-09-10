<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\CostCenterAllocation;
use App\Models\InternalCostCenter;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('Internal Cost Center policy maps exactly the accepted capabilities', function (string $ability, string $permission): void {
    givePermissionWithTenant($this->user, $this->tenant->id, $permission);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    $center = InternalCostCenter::factory()->forTenant($this->tenant->id)->create();
    $target = in_array($ability, ['viewAny', 'create'], true) ? InternalCostCenter::class : $center;

    expect(Gate::forUser($this->user)->allows($ability, $target))->toBeTrue();
})->with([
    ['viewAny', 'internal_cost_centers.read'],
    ['view', 'internal_cost_centers.read'],
    ['create', 'internal_cost_centers.create'],
    ['update', 'internal_cost_centers.update'],
    ['deactivate', 'internal_cost_centers.deactivate'],
]);

test('Internal Cost Center policy rejects foreign tenant models and legacy permission aliases', function (): void {
    givePermissionWithTenant($this->user, $this->tenant->id, 'internal_cost_centers.update');
    givePermissionWithTenant($this->user, $this->tenant->id, 'cost-centers.update');
    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreign = InternalCostCenter::factory()->forTenant($foreignTenant->id)->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

    expect(Gate::forUser($this->user)->allows('update', $foreign))->toBeFalse();
});

test('allocation policy uses only the two accepted dedicated capabilities', function (string $ability, string $permission): void {
    givePermissionWithTenant($this->user, $this->tenant->id, $permission);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

    expect(Gate::forUser($this->user)->allows($ability, CostCenterAllocation::class))->toBeTrue();
})->with([
    ['viewAny', 'cost_center_allocations.read'],
    ['update', 'cost_center_allocations.update'],
]);
