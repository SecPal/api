<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Contract;
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
    $this->foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('Contract policy maps abilities one-to-one to accepted capabilities', function (string $permission, string $ability): void {
    givePermissionWithTenant($this->actor, $this->tenant->id, $permission);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    $contract = Contract::factory()->retired()->create(['tenant_id' => $this->tenant->id]);
    $arguments = in_array($ability, ['viewAny', 'create'], true) ? Contract::class : $contract;

    expect(Gate::forUser($this->actor)->allows($ability, $arguments))->toBeTrue();
})->with([
    ['contracts.read', 'viewAny'],
    ['contracts.read', 'view'],
    ['contracts.create', 'create'],
    ['contracts.update', 'update'],
    ['contracts.retire', 'retire'],
]);

test('Contract policy requires active actor tenant context', function (): void {
    givePermissionWithTenant($this->actor, $this->tenant->id, 'contracts.read');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->foreignTenant->id);

    expect(Gate::forUser($this->actor)->allows('viewAny', Contract::class))->toBeFalse();
});

test('instance policy enforces tenant equality but not lifecycle state', function (string $ability): void {
    $permission = $ability === 'view' ? 'contracts.read' : 'contracts.'.$ability;
    givePermissionWithTenant($this->actor, $this->tenant->id, $permission);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    $localRetired = Contract::factory()->retired()->create(['tenant_id' => $this->tenant->id]);
    $foreign = Contract::factory()->create(['tenant_id' => $this->foreignTenant->id]);

    expect(Gate::forUser($this->actor)->allows($ability, $localRetired))->toBeTrue()
        ->and(Gate::forUser($this->actor)->allows($ability, $foreign))->toBeFalse();
})->with(['view', 'update', 'retire']);
