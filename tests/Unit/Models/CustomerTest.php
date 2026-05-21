<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Customer;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());

    TenantKey::ensureKekExists();

    // Create tenant for testing
    if (! TenantKey::first()) {
        $keys = TenantKey::generateEnvelopeKeys();
        $this->tenant = TenantKey::create($keys);
    } else {
        $this->tenant = TenantKey::first();
    }
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('customer can be created with factory', function (): void {
    $customer = Customer::factory()->create();

    expect($customer->id)->not->toBeNull()
        ->and($customer->customer_number)->not->toBeNull()
        ->and($customer->name)->not->toBeNull()
        ->and($customer->billing_address)->toBeArray()
        ->and($customer->is_active)->toBeTrue();
});

test('customer number is auto generated with correct format', function (): void {
    $customer = Customer::factory()->create();

    // Format: KD-YYYY-NNNN
    $pattern = '/^KD-\d{4}-\d{4}$/';
    expect($customer->customer_number)->toMatch($pattern);
});

test('generate customer number starts at 0001 for new year', function (): void {
    $year = now()->year;
    $number = Customer::generateCustomerNumber($this->tenant->id);

    $expected = sprintf('KD-%d-0001', $year);
    expect($number)->toBe($expected);
});

test('generate customer number increments correctly', function (): void {
    // Create first customer
    Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_number' => Customer::generateCustomerNumber($this->tenant->id),
    ]);

    // Generate next number
    $nextNumber = Customer::generateCustomerNumber($this->tenant->id);

    $year = now()->year;
    $expected = sprintf('KD-%d-0002', $year);
    expect($nextNumber)->toBe($expected);
});

test('generate customer number handles soft deleted records', function (): void {
    // Create and soft delete a customer
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_number' => Customer::generateCustomerNumber($this->tenant->id),
    ]);
    $customer->delete();

    // Next number should skip the deleted one
    $nextNumber = Customer::generateCustomerNumber($this->tenant->id);

    $year = now()->year;
    $expected = sprintf('KD-%d-0002', $year);
    expect($nextNumber)->toBe($expected);
});

test('scope active returns only active customers', function (): void {
    Customer::factory()->create(['is_active' => true]);
    Customer::factory()->create(['is_active' => true]);
    Customer::factory()->create(['is_active' => false]);

    $activeCustomers = Customer::active()->get();

    expect($activeCustomers)->toHaveCount(2)
        ->and($activeCustomers->every(fn ($customer) => $customer->is_active))->toBeTrue();
});

test('scope for tenant filters by tenant', function (): void {
    // Create another tenant
    $keys = TenantKey::generateEnvelopeKeys();
    $otherTenant = TenantKey::create($keys);

    // Create customers for both tenants
    Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    Customer::factory()->create(['tenant_id' => $otherTenant->id]);

    $customers = Customer::forTenant($this->tenant->id)->get();

    expect($customers)->toHaveCount(1)
        ->and($customers->first()->tenant_id)->toBe($this->tenant->id);
});

test('customer has tenant relationship', function (): void {
    $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($customer->tenant)->toBeInstanceOf(TenantKey::class)
        ->and($customer->tenant->id)->toBe($this->tenant->id);
});

test('customer has sites relationship', function (): void {
    $customer = Customer::factory()->create();

    expect($customer->sites)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
});

test('customer has assignments relationship', function (): void {
    $customer = Customer::factory()->create();

    expect($customer->assignments())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\HasMany::class)
        ->and($customer->assignments)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
});

test('customer billing address is cast to array', function (): void {
    $customer = Customer::factory()->create([
        'billing_address' => [
            'street' => 'Teststr. 123',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
        ],
    ]);

    expect($customer->billing_address)->toBeArray()
        ->and($customer->billing_address['street'])->toBe('Teststr. 123')
        ->and($customer->billing_address['city'])->toBe('Berlin');
});

test('customer contact is cast to array', function (): void {
    $customer = Customer::factory()->create([
        'contact' => [
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'phone' => '+49 30 12345678',
            'position' => 'Facility Manager',
        ],
    ]);

    expect($customer->contact)->toBeArray()
        ->and($customer->contact['name'])->toBe('Max Mustermann');
});

test('customer can be soft deleted', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $customerId = $customer->id;

    $customer->delete();

    $this->assertSoftDeleted('customers', ['id' => $customerId]);
    expect($customer->fresh()?->deleted_at)->not->toBeNull();
});

test('customer can be inactive', function (): void {
    $customer = Customer::factory()->inactive()->create();

    expect($customer->is_active)->toBeFalse();
});

test('customer metadata is nullable', function (): void {
    $customer = Customer::factory()->create(['metadata' => null]);

    expect($customer->metadata)->toBeNull();
});

test('customer notes is nullable', function (): void {
    $customer = Customer::factory()->create(['notes' => null]);

    expect($customer->notes)->toBeNull();
});
