<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Customer;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property Customer $customer
 * @property OrganizationalUnit $organizationalUnit
 * @property Site $site
 * @property User $user
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
        'organizational_unit_id' => $this->organizationalUnit->id,
    ]);
    $this->user = User::factory()->create();
});

test('site assignment has required relationships', function (): void {
    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
    ]);

    expect($assignment->tenant)->toBeInstanceOf(TenantKey::class)
        ->and($assignment->site)->toBeInstanceOf(Site::class)
        ->and($assignment->user)->toBeInstanceOf(User::class);
});

test('site assignment belongs to tenant', function (): void {
    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
    ]);

    expect($assignment->tenant->id)->toBe($this->tenant->id);
});

test('site assignment belongs to site', function (): void {
    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
    ]);

    expect($assignment->site->id)->toBe($this->site->id);
});

test('site assignment belongs to user', function (): void {
    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
    ]);

    expect($assignment->user->id)->toBe($this->user->id);
});

test('site assignment can have flexible role names', function (): void {
    $roles = ['Account Manager', 'Site Manager', 'Operations Lead', 'Quality Manager', 'Objektleiter'];

    foreach ($roles as $role) {
        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $this->user->id,
            'role' => $role,
        ]);

        expect($assignment->role)->toBe($role);
    }
});

test('forUser scope filters assignments by user', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $user1->id,
    ]);

    SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $user2->id,
    ]);

    $assignments = SiteAssignment::forUser($user1->id)->get();

    expect($assignments)->toHaveCount(1)
        ->and($assignments->first()->user_id)->toBe($user1->id);
});

test('forRole scope filters assignments by role', function (): void {
    $user2 = User::factory()->create();

    SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
        'role' => 'Account Manager',
    ]);

    SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $user2->id,
        'role' => 'Site Manager',
    ]);

    $assignments = SiteAssignment::forRole('Account Manager')->get();

    expect($assignments->count())->toBeGreaterThanOrEqual(1)
        ->and($assignments->where('role', 'Account Manager')->count())
        ->toBe($assignments->count());
});

test('forTenant scope filters assignments by tenant', function (): void {
    $keys2 = TenantKey::generateEnvelopeKeys();
    $tenant2 = TenantKey::create($keys2);
    $customer2 = Customer::factory()->create(['tenant_id' => $tenant2->id]);
    $orgUnit2 = OrganizationalUnit::factory()->create(['tenant_id' => $tenant2->id]);
    $site2 = Site::factory()->create([
        'tenant_id' => $tenant2->id,
        'customer_id' => $customer2->id,
        'organizational_unit_id' => $orgUnit2->id,
    ]);

    SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
    ]);

    SiteAssignment::factory()->create([
        'tenant_id' => $tenant2->id,
        'site_id' => $site2->id,
        'user_id' => $this->user->id,
    ]);

    $assignments = SiteAssignment::forTenant($this->tenant->id)->get();

    expect($assignments)->toHaveCount(1)
        ->and($assignments->first()->tenant_id)->toBe($this->tenant->id);
});

test('currentlyActive scope filters active assignments', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $user3 = User::factory()->create();

    // Expired assignment
    $expired = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $user1->id,
        'role' => 'Expired Role',
        'valid_from' => now()->subMonths(6),
        'valid_until' => now()->subDays(10),
    ]);

    // Active assignment
    $active = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $user2->id,
        'role' => 'Active Role',
        'valid_from' => now()->subDays(10),
        'valid_until' => now()->addDays(10),
    ]);

    // Future assignment
    $future = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $user3->id,
        'role' => 'Future Role',
        'valid_from' => now()->addDays(10),
        'valid_until' => now()->addMonths(6),
    ]);

    $activeAssignments = SiteAssignment::currentlyActive()
        ->whereIn('id', [$expired->id, $active->id, $future->id])
        ->get();

    expect($activeAssignments)->toHaveCount(1)
        ->and($activeAssignments->first()->id)->toBe($active->id);
});

test('assignment is active when within validity period', function (): void {
    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
        'valid_from' => now()->subDays(10),
        'valid_until' => now()->addDays(10),
    ]);

    expect($assignment->is_active)->toBeTrue();
});

test('assignment is inactive when expired', function (): void {
    $user = User::factory()->create();

    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $user->id,
        'role' => 'Expired Test Role',
        'valid_from' => now()->subMonths(6),
        'valid_until' => now()->subDays(10),
    ]);

    expect($assignment->is_active)->toBeFalse();
});

test('assignment is inactive when not yet started', function (): void {
    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
        'valid_from' => now()->addDays(10),
        'valid_until' => now()->addMonths(6),
    ]);

    expect($assignment->is_active)->toBeFalse();
});

test('assignment is active when valid_from is null', function (): void {
    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
        'valid_from' => null,
        'valid_until' => now()->addDays(10),
    ]);

    expect($assignment->is_active)->toBeTrue();
});

test('assignment is active when valid_until is null', function (): void {
    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
        'valid_from' => now()->subDays(10),
        'valid_until' => null,
    ]);

    expect($assignment->is_active)->toBeTrue();
});

test('assignment is active when both validity dates are null', function (): void {
    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
        'valid_from' => null,
        'valid_until' => null,
    ]);

    expect($assignment->is_active)->toBeTrue();
});

test('assignment can have optional notes', function (): void {
    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
        'notes' => 'This is a test note',
    ]);

    expect($assignment->notes)->toBe('This is a test note');
});

test('assignment notes can be null', function (): void {
    $assignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $this->site->id,
        'user_id' => $this->user->id,
        'notes' => null,
    ]);

    expect($assignment->notes)->toBeNull();
});
