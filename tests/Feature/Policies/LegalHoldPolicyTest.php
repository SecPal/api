<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\LegalHold;
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
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('policy capabilities map one-to-one to accepted permissions', function (string $permission, string $ability): void {
    givePermissionWithTenant($this->actor, $this->tenant->id, $permission);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    $hold = LegalHold::factory()->released()->create(['tenant_id' => $this->tenant->id]);
    $arguments = in_array($ability, ['viewAny', 'create'], true) ? LegalHold::class : $hold;
    expect(Gate::forUser($this->actor)->allows($ability, $arguments))->toBeTrue();
})->with([
    ['legal_holds.read', 'viewAny'],
    ['legal_holds.read', 'view'],
    ['legal_holds.create', 'create'],
    ['legal_holds.attach', 'attach'],
    ['legal_holds.detach', 'detach'],
    ['legal_holds.release', 'release'],
]);

test('policy denies missing permissions and inactive tenant context', function (): void {
    givePermissionWithTenant($this->actor, $this->tenant->id, 'legal_holds.read');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->foreignTenant->id);
    expect(Gate::forUser($this->actor)->allows('viewAny', LegalHold::class))->toBeFalse()
        ->and(Gate::forUser($this->actor)->allows('create', LegalHold::class))->toBeFalse();
});

test('instance policies enforce tenant equality without lifecycle state', function (string $ability): void {
    $permission = $ability === 'view' ? 'legal_holds.read' : 'legal_holds.'.$ability;
    givePermissionWithTenant($this->actor, $this->tenant->id, $permission);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    $localReleased = LegalHold::factory()->released()->create(['tenant_id' => $this->tenant->id]);
    $foreign = LegalHold::factory()->create(['tenant_id' => $this->foreignTenant->id]);
    expect(Gate::forUser($this->actor)->allows($ability, $localReleased))->toBeTrue()
        ->and(Gate::forUser($this->actor)->allows($ability, $foreign))->toBeFalse();
})->with(['view', 'attach', 'detach', 'release']);
