<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\ServiceBooking;
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

test('Service Booking policy maps abilities one-to-one to accepted capabilities', function (string $permission, string $ability): void {
    givePermissionWithTenant($this->actor, $this->tenant->id, $permission);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    $booking = ServiceBooking::factory()->invoiced()->retired()->create(['tenant_id' => $this->tenant->id]);
    $arguments = in_array($ability, ['viewAny', 'create'], true) ? ServiceBooking::class : $booking;

    expect(Gate::forUser($this->actor)->allows($ability, $arguments))->toBeTrue();
})->with([
    ['service_bookings.read', 'viewAny'],
    ['service_bookings.read', 'view'],
    ['service_bookings.create', 'create'],
    ['service_bookings.update', 'update'],
    ['service_bookings.retire', 'retire'],
]);

test('Service Booking policy requires active actor tenant context', function (): void {
    givePermissionWithTenant($this->actor, $this->tenant->id, 'service_bookings.read');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->foreignTenant->id);

    expect(Gate::forUser($this->actor)->allows('viewAny', ServiceBooking::class))->toBeFalse();
});

test('instance policy enforces tenant equality but not lifecycle or invoice state', function (string $ability): void {
    $permission = $ability === 'view' ? 'service_bookings.read' : 'service_bookings.'.$ability;
    givePermissionWithTenant($this->actor, $this->tenant->id, $permission);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    $local = ServiceBooking::factory()->invoiced()->retired()->create(['tenant_id' => $this->tenant->id]);
    $foreign = ServiceBooking::factory()->create(['tenant_id' => $this->foreignTenant->id]);

    expect(Gate::forUser($this->actor)->allows($ability, $local))->toBeTrue()
        ->and(Gate::forUser($this->actor)->allows($ability, $foreign))->toBeFalse();
})->with(['view', 'update', 'retire']);
