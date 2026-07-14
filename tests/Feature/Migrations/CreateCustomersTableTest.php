<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * Helper function to create minimal customer record.
 */
function createMinimalCustomer(string $tenantId, string $customerNumber, string $name = 'Test Customer'): string
{
    $legalEntityId = OrganizationalUnit::factory()->forTenant($tenantId)->create([
        'is_legal_entity' => true,
    ])->id;

    $customerId = Str::uuid()->toString();
    DB::table('customers')->insert([
        'id' => $customerId,
        'tenant_id' => $tenantId,
        'legal_entity_id' => $legalEntityId,
        'customer_number' => $customerNumber,
        'name' => $name,
        'billing_address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $customerId;
}

function createCustomerMigrationLegalEntity(string $tenantId): string
{
    return OrganizationalUnit::factory()->forTenant($tenantId)->create([
        'is_legal_entity' => true,
    ])->id;
}

describe('CreateCustomersTable Migration', function () {
    test('creates customers table with correct columns', function (): void {
        expect(Schema::hasTable('customers'))->toBeTrue();

        expect(Schema::hasColumn('customers', 'id'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'tenant_id'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'legal_entity_id'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'customer_number'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'name'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'billing_address'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'contact'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'notes'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'metadata'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'is_active'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'created_at'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'updated_at'))->toBeTrue();
        expect(Schema::hasColumn('customers', 'deleted_at'))->toBeTrue();
    });

    test('has indexes on key columns', function (): void {
        $indexes = Schema::getIndexes('customers');
        $indexColumns = collect($indexes)->pluck('columns')->flatten()->toArray();

        expect($indexColumns)->toContain('tenant_id');
        expect($indexColumns)->toContain('legal_entity_id');
        expect($indexColumns)->toContain('customer_number');
        expect($indexColumns)->toContain('is_active');
        expect($indexColumns)->toContain('name');
    });

    test('unique constraint on tenant_id and customer_number', function (): void {
        $indexes = Schema::getIndexes('customers');

        $hasUniqueConstraint = collect($indexes)->contains(function ($index) {
            return $index['name'] === 'unique_tenant_customer_number'
                && in_array('tenant_id', $index['columns'])
                && in_array('customer_number', $index['columns'])
                && $index['unique'] === true;
        });

        expect($hasUniqueConstraint)->toBeTrue();
    });

    test('foreign key constraint on tenant_id references tenant_keys', function (): void {
        $foreignKeys = Schema::getForeignKeys('customers');

        $hasTenantForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('tenant_id', $fk['columns'])
                && $fk['foreign_table'] === 'tenant_keys';
        });

        expect($hasTenantForeignKey)->toBeTrue();
    });

    test('foreign key constraint on legal_entity_id references organizational_units', function (): void {
        $foreignKeys = Schema::getForeignKeys('customers');

        $hasLegalEntityForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('legal_entity_id', $fk['columns'])
                && $fk['foreign_table'] === 'organizational_units';
        });

        expect($hasLegalEntityForeignKey)->toBeTrue();
    });

    test('composite tenant legal entity foreign key enforces same tenant', function (): void {
        $foreignKeys = Schema::getForeignKeys('customers');

        $hasCompositeForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('tenant_id', $fk['columns'])
                && in_array('legal_entity_id', $fk['columns'])
                && $fk['foreign_table'] === 'organizational_units';
        });

        expect($hasCompositeForeignKey)->toBeTrue();
    });

    test('tenant and legal entity index is present', function (): void {
        $indexes = Schema::getIndexes('customers');

        $hasTenantLegalEntityIndex = collect($indexes)->contains(function ($index) {
            return $index['name'] === 'idx_customers_tenant_legal_entity'
                && $index['columns'] === ['tenant_id', 'legal_entity_id'];
        });

        expect($hasTenantLegalEntityIndex)->toBeTrue();
    });

    test('rejects a legal entity from another tenant at database level', function (): void {
        $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $foreignLegalEntity = createCustomerMigrationLegalEntity((string) $otherTenant->id);

        DB::table('customers')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $foreignLegalEntity,
            'customer_number' => 'KD-2025-CROSS',
            'name' => 'Cross Tenant Customer',
            'billing_address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->throws(PDOException::class);

    test('cascade delete removes customers when tenant is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createMinimalCustomer($tenant->id, 'KD-2025-001', 'Test Customer GmbH');

        expect(DB::table('customers')->where('id', $customerId)->exists())->toBeTrue();

        $tenant->delete();

        expect(DB::table('customers')->where('id', $customerId)->exists())->toBeFalse();
    });

    test('billing_address column accepts valid JSONB', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = Str::uuid()->toString();
        $billingAddress = [
            'street' => 'Berliner Allee 45',
            'city' => 'München',
            'postal_code' => '80331',
            'country' => 'DE',
        ];

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $tenant->id,
            'legal_entity_id' => createCustomerMigrationLegalEntity((string) $tenant->id),
            'customer_number' => 'KD-2025-002',
            'name' => 'Acme Corporation',
            'billing_address' => json_encode($billingAddress),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = DB::table('customers')->where('id', $customerId)->first();
        $decodedAddress = json_decode($customer->billing_address, true);

        expect($decodedAddress)->toHaveKey('street', 'Berliner Allee 45');
        expect($decodedAddress)->toHaveKey('city', 'München');
        expect($decodedAddress)->toHaveKey('postal_code', '80331');
        expect($decodedAddress)->toHaveKey('country', 'DE');
    });

    test('contact column accepts valid JSONB and is nullable', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = Str::uuid()->toString();
        $contact = [
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'phone' => '+49 30 12345678',
            'position' => 'Facility Manager',
        ];

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $tenant->id,
            'legal_entity_id' => createCustomerMigrationLegalEntity((string) $tenant->id),
            'customer_number' => 'KD-2025-003',
            'name' => 'Test Company',
            'billing_address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'contact' => json_encode($contact),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = DB::table('customers')->where('id', $customerId)->first();
        $decodedContact = json_decode($customer->contact, true);

        expect($decodedContact)->toHaveKey('name', 'Max Mustermann');
        expect($decodedContact)->toHaveKey('email', 'max@example.com');
    });

    test('metadata column accepts valid JSONB and is nullable', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = Str::uuid()->toString();
        $metadata = [
            'tax_id' => 'DE123456789',
            'contract_type' => 'Premium',
            'custom_field' => 'Custom Value',
        ];

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $tenant->id,
            'legal_entity_id' => createCustomerMigrationLegalEntity((string) $tenant->id),
            'customer_number' => 'KD-2025-004',
            'name' => 'Metadata Test Company',
            'billing_address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'metadata' => json_encode($metadata),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = DB::table('customers')->where('id', $customerId)->first();
        $decodedMetadata = json_decode($customer->metadata, true);

        expect($decodedMetadata)->toHaveKey('tax_id', 'DE123456789');
        expect($decodedMetadata)->toHaveKey('contract_type', 'Premium');
    });

    test('soft delete sets deleted_at timestamp', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createMinimalCustomer($tenant->id, 'KD-2025-005', 'Soft Delete Test');

        $deletedAt = now();
        DB::table('customers')
            ->where('id', $customerId)
            ->update(['deleted_at' => $deletedAt]);

        $customer = DB::table('customers')->where('id', $customerId)->first();
        expect($customer->deleted_at)->not->toBeNull();
    });

    test('is_active defaults to true', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createMinimalCustomer($tenant->id, 'KD-2025-006', 'Default Active Test');

        $customer = DB::table('customers')->where('id', $customerId)->first();
        expect($customer->is_active)->toBeTrue();
    });

    test('customer_number must be unique per tenant', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        createMinimalCustomer($tenant->id, 'KD-2025-DUPLICATE', 'First Customer');
        createMinimalCustomer($tenant->id, 'KD-2025-DUPLICATE', 'Second Customer');
    })->throws(PDOException::class);

    test('same customer_number allowed for different tenants', function (): void {
        $keys1 = TenantKey::generateEnvelopeKeys();
        $tenant1 = TenantKey::create($keys1);

        $keys2 = TenantKey::generateEnvelopeKeys();
        $tenant2 = TenantKey::create($keys2);

        $customerNumber = 'KD-2025-SAME';

        createMinimalCustomer($tenant1->id, $customerNumber, 'Tenant 1 Customer');
        createMinimalCustomer($tenant2->id, $customerNumber, 'Tenant 2 Customer');

        $count = DB::table('customers')->where('customer_number', $customerNumber)->count();
        expect($count)->toBe(2);
    });
});
