<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * Helper function to create a test customer.
 */
function createTestCustomer(string $tenantId, string $customerNumber = 'KD-TEST-001'): string
{
    $customerId = Str::uuid()->toString();
    DB::table('customers')->insert([
        'id' => $customerId,
        'tenant_id' => $tenantId,
        'customer_number' => $customerNumber,
        'name' => 'Test Customer',
        'billing_address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $customerId;
}

/**
 * Helper function to create a test organizational unit.
 */
function createTestOrgUnit(string $tenantId, string $type = 'branch'): string
{
    $orgUnitId = Str::uuid()->toString();
    DB::table('organizational_units')->insert([
        'id' => $orgUnitId,
        'tenant_id' => $tenantId,
        'name' => 'Test Unit',
        'type' => $type,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $orgUnitId;
}

describe('CreateSitesTable Migration', function () {
    test('creates sites table with correct columns', function (): void {
        expect(Schema::hasTable('sites'))->toBeTrue();

        expect(Schema::hasColumn('sites', 'id'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'tenant_id'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'customer_id'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'organizational_unit_id'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'site_number'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'name'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'type'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'address'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'contact'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'access_instructions'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'notes'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'metadata'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'is_active'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'valid_from'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'valid_until'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'created_at'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'updated_at'))->toBeTrue();
        expect(Schema::hasColumn('sites', 'deleted_at'))->toBeTrue();
    });

    test('has indexes on key columns', function (): void {
        $indexes = Schema::getIndexes('sites');
        $indexColumns = collect($indexes)->pluck('columns')->flatten()->toArray();

        expect($indexColumns)->toContain('tenant_id');
        expect($indexColumns)->toContain('site_number');
        expect($indexColumns)->toContain('customer_id');
        expect($indexColumns)->toContain('organizational_unit_id');
        expect($indexColumns)->toContain('type');
        expect($indexColumns)->toContain('is_active');
    });

    test('unique constraint on tenant_id and site_number', function (): void {
        $indexes = Schema::getIndexes('sites');

        $hasUniqueConstraint = collect($indexes)->contains(function ($index) {
            return $index['name'] === 'unique_tenant_site_number'
                && in_array('tenant_id', $index['columns'])
                && in_array('site_number', $index['columns'])
                && $index['unique'] === true;
        });

        expect($hasUniqueConstraint)->toBeTrue();
    });

    test('foreign key constraint on tenant_id references tenant_keys', function (): void {
        $foreignKeys = Schema::getForeignKeys('sites');

        $hasTenantForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('tenant_id', $fk['columns'])
                && $fk['foreign_table'] === 'tenant_keys';
        });

        expect($hasTenantForeignKey)->toBeTrue();
    });

    test('foreign key constraint on customer_id references customers', function (): void {
        $foreignKeys = Schema::getForeignKeys('sites');

        $hasCustomerForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('customer_id', $fk['columns'])
                && $fk['foreign_table'] === 'customers';
        });

        expect($hasCustomerForeignKey)->toBeTrue();
    });

    test('foreign key constraint on organizational_unit_id references organizational_units', function (): void {
        $foreignKeys = Schema::getForeignKeys('sites');

        $hasOrgUnitForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('organizational_unit_id', $fk['columns'])
                && $fk['foreign_table'] === 'organizational_units';
        });

        expect($hasOrgUnitForeignKey)->toBeTrue();
    });

    test('cascade delete removes sites when tenant is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = createTestCustomer($tenant->id, 'KD-2025-001');
        $orgUnitId = createTestOrgUnit($tenant->id);

        // Create site
        $siteId = Str::uuid()->toString();
        DB::table('sites')->insert([
            'id' => $siteId,
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'organizational_unit_id' => $orgUnitId,
            'site_number' => 'OBJ-2025-001',
            'name' => 'Test Site',
            'type' => 'permanent',
            'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::table('sites')->where('id', $siteId)->exists())->toBeTrue();

        $tenant->delete();

        expect(DB::table('sites')->where('id', $siteId)->exists())->toBeFalse();
    });

    test('cascade delete removes sites when customer is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = createTestCustomer($tenant->id, 'KD-2025-002');
        $orgUnitId = createTestOrgUnit($tenant->id);

        $siteId = Str::uuid()->toString();
        DB::table('sites')->insert([
            'id' => $siteId,
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'organizational_unit_id' => $orgUnitId,
            'site_number' => 'OBJ-2025-002',
            'name' => 'Test Site',
            'type' => 'permanent',
            'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::table('sites')->where('id', $siteId)->exists())->toBeTrue();

        DB::table('customers')->where('id', $customerId)->delete();

        expect(DB::table('sites')->where('id', $siteId)->exists())->toBeFalse();
    });

    test('type column accepts permanent and temporary values', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = createTestCustomer($tenant->id, 'KD-2025-003');
        $orgUnitId = createTestOrgUnit($tenant->id);

        $types = ['permanent', 'temporary'];

        foreach ($types as $type) {
            $siteId = Str::uuid()->toString();
            DB::table('sites')->insert([
                'id' => $siteId,
                'tenant_id' => $tenant->id,
                'customer_id' => $customerId,
                'organizational_unit_id' => $orgUnitId,
                'site_number' => "OBJ-2025-{$type}",
                'name' => "Test Site {$type}",
                'type' => $type,
                'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $site = DB::table('sites')->where('id', $siteId)->first();
            expect($site->type)->toBe($type);
        }
    });

    test('type column rejects invalid enum values', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = Str::uuid()->toString();
        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $tenant->id,
            'customer_number' => 'KD-2025-INVALID',
            'name' => 'Test Customer',
            'billing_address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgUnitId = Str::uuid()->toString();
        DB::table('organizational_units')->insert([
            'id' => $orgUnitId,
            'tenant_id' => $tenant->id,
            'name' => 'Test Unit',
            'type' => 'branch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sites')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'organizational_unit_id' => $orgUnitId,
            'site_number' => 'OBJ-2025-INVALID',
            'name' => 'Invalid Type Site',
            'type' => 'invalid_type',
            'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->throws(PDOException::class);

    test('address column accepts valid JSONB with GPS coordinates', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = createTestCustomer($tenant->id, 'KD-2025-004');
        $orgUnitId = createTestOrgUnit($tenant->id);

        $address = [
            'street' => 'Brandenburger Tor',
            'city' => 'Berlin',
            'postal_code' => '10117',
            'country' => 'DE',
            'lat' => 52.5163,
            'lng' => 13.3777,
        ];

        $siteId = Str::uuid()->toString();
        DB::table('sites')->insert([
            'id' => $siteId,
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'organizational_unit_id' => $orgUnitId,
            'site_number' => 'OBJ-2025-GPS',
            'name' => 'GPS Test Site',
            'type' => 'permanent',
            'address' => json_encode($address),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $site = DB::table('sites')->where('id', $siteId)->first();
        $decodedAddress = json_decode($site->address, true);

        expect($decodedAddress)->toHaveKey('street', 'Brandenburger Tor');
        expect($decodedAddress)->toHaveKey('lat', 52.5163);
        expect($decodedAddress)->toHaveKey('lng', 13.3777);
    });

    test('contact column accepts valid JSONB and is nullable', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = createTestCustomer($tenant->id, 'KD-2025-005');
        $orgUnitId = createTestOrgUnit($tenant->id);

        $contact = [
            'name' => 'Site Manager',
            'email' => 'manager@site.com',
            'phone' => '+49 30 987654',
            'position' => 'Facility Manager',
        ];

        $siteId = Str::uuid()->toString();
        DB::table('sites')->insert([
            'id' => $siteId,
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'organizational_unit_id' => $orgUnitId,
            'site_number' => 'OBJ-2025-CONTACT',
            'name' => 'Contact Test Site',
            'type' => 'permanent',
            'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'contact' => json_encode($contact),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $site = DB::table('sites')->where('id', $siteId)->first();
        $decodedContact = json_decode($site->contact, true);

        expect($decodedContact)->toHaveKey('name', 'Site Manager');
        expect($decodedContact)->toHaveKey('email', 'manager@site.com');
    });

    test('soft delete sets deleted_at timestamp', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = createTestCustomer($tenant->id, 'KD-2025-006');
        $orgUnitId = createTestOrgUnit($tenant->id);

        $siteId = Str::uuid()->toString();
        DB::table('sites')->insert([
            'id' => $siteId,
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'organizational_unit_id' => $orgUnitId,
            'site_number' => 'OBJ-2025-SOFT',
            'name' => 'Soft Delete Test',
            'type' => 'permanent',
            'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deletedAt = now();
        DB::table('sites')
            ->where('id', $siteId)
            ->update(['deleted_at' => $deletedAt]);

        $site = DB::table('sites')->where('id', $siteId)->first();
        expect($site->deleted_at)->not->toBeNull();
    });

    test('is_active defaults to true', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = createTestCustomer($tenant->id, 'KD-2025-007');
        $orgUnitId = createTestOrgUnit($tenant->id);

        $siteId = Str::uuid()->toString();
        DB::table('sites')->insert([
            'id' => $siteId,
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'organizational_unit_id' => $orgUnitId,
            'site_number' => 'OBJ-2025-DEFAULT',
            'name' => 'Default Active Test',
            'type' => 'permanent',
            'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $site = DB::table('sites')->where('id', $siteId)->first();
        expect($site->is_active)->toBeTrue();
    });

    test('site_number must be unique per tenant', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = createTestCustomer($tenant->id, 'KD-2025-008');
        $orgUnitId = createTestOrgUnit($tenant->id);

        DB::table('sites')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'organizational_unit_id' => $orgUnitId,
            'site_number' => 'OBJ-2025-DUP',
            'name' => 'First Site',
            'type' => 'permanent',
            'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sites')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'organizational_unit_id' => $orgUnitId,
            'site_number' => 'OBJ-2025-DUP',
            'name' => 'Second Site',
            'type' => 'permanent',
            'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->throws(PDOException::class);

    test('same site_number allowed for different tenants', function (): void {
        $keys1 = TenantKey::generateEnvelopeKeys();
        $tenant1 = TenantKey::create($keys1);

        $keys2 = TenantKey::generateEnvelopeKeys();
        $tenant2 = TenantKey::create($keys2);

        $customerId1 = createTestCustomer($tenant1->id, 'KD-T1-001');
        $orgUnitId1 = createTestOrgUnit($tenant1->id);

        $customerId2 = createTestCustomer($tenant2->id, 'KD-T2-001');
        $orgUnitId2 = createTestOrgUnit($tenant2->id);

        $siteNumber = 'OBJ-2025-SAME';

        DB::table('sites')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant1->id,
            'customer_id' => $customerId1,
            'organizational_unit_id' => $orgUnitId1,
            'site_number' => $siteNumber,
            'name' => 'Tenant 1 Site',
            'type' => 'permanent',
            'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sites')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant2->id,
            'customer_id' => $customerId2,
            'organizational_unit_id' => $orgUnitId2,
            'site_number' => $siteNumber,
            'name' => 'Tenant 2 Site',
            'type' => 'permanent',
            'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DB::table('sites')->where('site_number', $siteNumber)->count();
        expect($count)->toBe(2);
    });

    test('valid_from and valid_until dates work for temporary sites', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $customerId = createTestCustomer($tenant->id, 'KD-2025-009');
        $orgUnitId = createTestOrgUnit($tenant->id);

        $siteId = Str::uuid()->toString();
        $validFrom = now()->toDateString();
        $validUntil = now()->addDays(30)->toDateString();

        DB::table('sites')->insert([
            'id' => $siteId,
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'organizational_unit_id' => $orgUnitId,
            'site_number' => 'OBJ-2025-TEMP',
            'name' => 'Temporary Event Site',
            'type' => 'temporary',
            'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $site = DB::table('sites')->where('id', $siteId)->first();
        expect($site->valid_from)->toBe($validFrom);
        expect($site->valid_until)->toBe($validUntil);
    });
});
