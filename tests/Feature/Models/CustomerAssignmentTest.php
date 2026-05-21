<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property Customer $customer
 * @property User $user
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::ensureKekExists();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->user = User::factory()->create();
});

test('customer assignment has required relationships', function (): void {
    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    expect($assignment->tenant)->toBeInstanceOf(TenantKey::class)
        ->and($assignment->customer)->toBeInstanceOf(Customer::class)
        ->and($assignment->user)->toBeInstanceOf(User::class);
});

test('customer assignment belongs to tenant', function (): void {
    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    expect($assignment->tenant->id)->toBe($this->tenant->id);
});

test('customer assignment belongs to customer', function (): void {
    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    expect($assignment->customer->id)->toBe($this->customer->id);
});

test('customer assignment belongs to user', function (): void {
    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    expect($assignment->user->id)->toBe($this->user->id);
});

test('customer assignment can have flexible role names', function (): void {
    $roles = ['Key Account Manager', 'Sales Representative', 'Support Contact', 'Billing Contact'];

    foreach ($roles as $role) {
        $assignment = CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'role' => $role,
        ]);

        expect($assignment->role)->toBe($role);
    }
});

test('forUser scope filters assignments by user', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $user1->id,
    ]);

    CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $user2->id,
    ]);

    $assignments = CustomerAssignment::forUser($user1->id)->get();

    expect($assignments)->toHaveCount(1)
        ->and($assignments->first()->user_id)->toBe($user1->id);
});

test('forRole scope filters assignments by role', function (): void {
    $user2 = User::factory()->create();

    CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'role' => 'Key Account Manager',
    ]);

    CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $user2->id,
        'role' => 'Sales Representative',
    ]);

    $assignments = CustomerAssignment::forRole('Key Account Manager')->get();

    expect($assignments->count())->toBeGreaterThanOrEqual(1)
        ->and($assignments->where('role', 'Key Account Manager')->count())
        ->toBe($assignments->count());
});

test('forTenant scope filters assignments by tenant', function (): void {
    $keys2 = TenantKey::generateEnvelopeKeys();
    $tenant2 = TenantKey::create($keys2);
    $customer2 = Customer::factory()->create(['tenant_id' => $tenant2->id]);

    CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    CustomerAssignment::factory()->create([
        'tenant_id' => $tenant2->id,
        'customer_id' => $customer2->id,
        'user_id' => $this->user->id,
    ]);

    $assignments = CustomerAssignment::forTenant($this->tenant->id)->get();

    expect($assignments)->toHaveCount(1)
        ->and($assignments->first()->tenant_id)->toBe($this->tenant->id);
});

test('currentlyActive scope filters active assignments', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $user3 = User::factory()->create();

    // Expired assignment
    $expired = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $user1->id,
        'role' => 'Expired Role',
        'valid_from' => now()->subMonths(6),
        'valid_until' => now()->subDays(10),
    ]);

    // Active assignment
    $active = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $user2->id,
        'role' => 'Active Role',
        'valid_from' => now()->subDays(10),
        'valid_until' => now()->addDays(10),
    ]);

    // Future assignment
    $future = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $user3->id,
        'role' => 'Future Role',
        'valid_from' => now()->addDays(10),
        'valid_until' => now()->addMonths(6),
    ]);

    $activeAssignments = CustomerAssignment::currentlyActive()
        ->whereIn('id', [$expired->id, $active->id, $future->id])
        ->get();

    expect($activeAssignments)->toHaveCount(1)
        ->and($activeAssignments->first()->id)->toBe($active->id);
});

test('assignment is active when within validity period', function (): void {
    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'valid_from' => now()->subDays(10),
        'valid_until' => now()->addDays(10),
    ]);

    expect($assignment->is_active)->toBeTrue();
});

test('assignment is inactive when expired', function (): void {
    $user = User::factory()->create();

    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $user->id,
        'role' => 'Expired Test Role',
        'valid_from' => now()->subMonths(6),
        'valid_until' => now()->subDays(10),
    ]);

    expect($assignment->is_active)->toBeFalse();
});

test('assignment is inactive when not yet started', function (): void {
    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'valid_from' => now()->addDays(10),
        'valid_until' => now()->addMonths(6),
    ]);

    expect($assignment->is_active)->toBeFalse();
});

test('assignment is active when valid_from is null', function (): void {
    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'valid_from' => null,
        'valid_until' => now()->addDays(10),
    ]);

    expect($assignment->is_active)->toBeTrue();
});

test('assignment is active when valid_until is null', function (): void {
    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'valid_from' => now()->subDays(10),
        'valid_until' => null,
    ]);

    expect($assignment->is_active)->toBeTrue();
});

test('assignment is active when both validity dates are null', function (): void {
    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'valid_from' => null,
        'valid_until' => null,
    ]);

    expect($assignment->is_active)->toBeTrue();
});

test('assignment can have optional notes', function (): void {
    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'notes' => 'This is a test note',
    ]);

    expect($assignment->notes)->toBe('This is a test note');
});

test('assignment notes can be null', function (): void {
    $assignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'notes' => null,
    ]);

    expect($assignment->notes)->toBeNull();
});
