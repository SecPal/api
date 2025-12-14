<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit\Models;

use App\Models\Customer;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for Customer model.
 *
 * @covers \App\Models\Customer
 */
class CustomerTest extends TestCase
{
    use RefreshDatabase;

    protected TenantKey $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        TenantKey::setKekPath(getTestKekPath());

        // Create tenant for testing
        if (! TenantKey::first()) {
            $keys = TenantKey::generateEnvelopeKeys();
            $this->tenant = TenantKey::create($keys);
        } else {
            $this->tenant = TenantKey::first();
        }
    }

    protected function tearDown(): void
    {
        cleanupTestKekFile();
        TenantKey::setKekPath(null);
        parent::tearDown();
    }

    public function test_customer_can_be_created_with_factory(): void
    {
        $customer = Customer::factory()->create();

        $this->assertNotNull($customer->id);
        $this->assertNotNull($customer->customer_number);
        $this->assertNotNull($customer->name);
        $this->assertIsArray($customer->billing_address);
        $this->assertTrue($customer->is_active);
    }

    public function test_customer_number_is_auto_generated_with_correct_format(): void
    {
        $customer = Customer::factory()->create();

        // Format: KD-YYYY-NNNN
        $pattern = '/^KD-\d{4}-\d{4}$/';
        $this->assertMatchesRegularExpression($pattern, $customer->customer_number);
    }

    public function test_generate_customer_number_starts_at_0001_for_new_year(): void
    {
        $year = now()->year;
        $number = Customer::generateCustomerNumber($this->tenant->id);

        $expected = sprintf('KD-%d-0001', $year);
        $this->assertSame($expected, $number);
    }

    public function test_generate_customer_number_increments_correctly(): void
    {
        // Create first customer
        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_number' => Customer::generateCustomerNumber($this->tenant->id),
        ]);

        // Generate next number
        $nextNumber = Customer::generateCustomerNumber($this->tenant->id);

        $year = now()->year;
        $expected = sprintf('KD-%d-0002', $year);
        $this->assertSame($expected, $nextNumber);
    }

    public function test_generate_customer_number_handles_soft_deleted_records(): void
    {
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
        $this->assertSame($expected, $nextNumber);
    }

    public function test_scope_active_returns_only_active_customers(): void
    {
        Customer::factory()->create(['is_active' => true]);
        Customer::factory()->create(['is_active' => true]);
        Customer::factory()->create(['is_active' => false]);

        $activeCustomers = Customer::active()->get();

        $this->assertCount(2, $activeCustomers);
        $this->assertTrue($activeCustomers->every(fn ($customer) => $customer->is_active));
    }

    public function test_scope_for_tenant_filters_by_tenant(): void
    {
        // Create another tenant
        $keys = TenantKey::generateEnvelopeKeys();
        $otherTenant = TenantKey::create($keys);

        // Create customers for both tenants
        Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        Customer::factory()->create(['tenant_id' => $otherTenant->id]);

        $customers = Customer::forTenant($this->tenant->id)->get();

        $this->assertCount(1, $customers);
        $this->assertEquals($this->tenant->id, $customers->first()->tenant_id);
    }

    public function test_customer_has_tenant_relationship(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->assertInstanceOf(TenantKey::class, $customer->tenant);
        $this->assertEquals($this->tenant->id, $customer->tenant->id);
    }

    public function test_customer_has_sites_relationship(): void
    {
        $customer = Customer::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $customer->sites);
    }

    public function test_customer_has_assignments_relationship(): void
    {
        $customer = Customer::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $customer->assignments);
    }

    public function test_customer_billing_address_is_cast_to_array(): void
    {
        $customer = Customer::factory()->create([
            'billing_address' => [
                'street' => 'Teststr. 123',
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
        ]);

        $this->assertIsArray($customer->billing_address);
        $this->assertEquals('Teststr. 123', $customer->billing_address['street']);
        $this->assertEquals('Berlin', $customer->billing_address['city']);
    }

    public function test_customer_contact_is_cast_to_array(): void
    {
        $customer = Customer::factory()->create([
            'contact' => [
                'name' => 'Max Mustermann',
                'email' => 'max@example.com',
                'phone' => '+49 30 12345678',
                'position' => 'Facility Manager',
            ],
        ]);

        $this->assertIsArray($customer->contact);
        $this->assertEquals('Max Mustermann', $customer->contact['name']);
    }

    public function test_customer_can_be_soft_deleted(): void
    {
        $customer = Customer::factory()->create();
        $customerId = $customer->id;

        $customer->delete();

        $this->assertSoftDeleted('customers', ['id' => $customerId]);
        $this->assertNotNull($customer->fresh()?->deleted_at);
    }

    public function test_customer_can_be_inactive(): void
    {
        $customer = Customer::factory()->inactive()->create();

        $this->assertFalse($customer->is_active);
    }

    public function test_customer_metadata_is_nullable(): void
    {
        $customer = Customer::factory()->create(['metadata' => null]);

        $this->assertNull($customer->metadata);
    }

    public function test_customer_notes_is_nullable(): void
    {
        $customer = Customer::factory()->create(['notes' => null]);

        $this->assertNull($customer->notes);
    }
}
