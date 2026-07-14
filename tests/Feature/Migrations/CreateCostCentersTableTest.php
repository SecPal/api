<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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
 * Helper function to create test organizational unit for cost centers.
 */
function createCostCenterTestOrgUnit(string $tenantId, string $name): string
{
    $orgUnitId = Str::uuid()->toString();
    DB::table('organizational_units')->insert([
        'id' => $orgUnitId,
        'tenant_id' => $tenantId,
        'type' => 'branch',
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $orgUnitId;
}

/**
 * Helper function to create test customer for cost centers.
 */
function createCostCenterTestCustomer(string $tenantId, string $customerNumber): string
{
    $customerId = Str::uuid()->toString();
    DB::table('customers')->insert([
        'id' => $customerId,
        'tenant_id' => $tenantId,
        'legal_entity_id' => createCostCenterLegalEntity($tenantId),
        'customer_number' => $customerNumber,
        'name' => 'Test Customer',
        'billing_address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $customerId;
}

function createCostCenterLegalEntity(string $tenantId): string
{
    $legalEntityId = Str::uuid()->toString();
    DB::table('organizational_units')->insert([
        'id' => $legalEntityId,
        'tenant_id' => $tenantId,
        'type' => 'company',
        'name' => 'Cost Center Legal Entity',
        'is_legal_entity' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $legalEntityId;
}

/**
 * Helper function to create test site for cost centers.
 */
function createCostCenterTestSite(string $tenantId, string $customerId, string $orgUnitId, string $siteNumber): string
{
    $siteId = Str::uuid()->toString();
    DB::table('sites')->insert([
        'id' => $siteId,
        'tenant_id' => $tenantId,
        'customer_id' => $customerId,
        'organizational_unit_id' => $orgUnitId,
        'site_number' => $siteNumber,
        'name' => 'Test Site',
        'type' => 'permanent',
        'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $siteId;
}

/**
 * Helper function to create minimal cost center record.
 */
function createMinimalCostCenter(string $tenantId, string $siteId, string $code, string $name = 'Test Cost Center'): string
{
    $costCenterId = Str::uuid()->toString();
    DB::table('cost_centers')->insert([
        'id' => $costCenterId,
        'tenant_id' => $tenantId,
        'site_id' => $siteId,
        'code' => $code,
        'name' => $name,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $costCenterId;
}

describe('CreateCostCentersTable Migration', function () {
    test('creates cost_centers table with correct columns', function (): void {
        expect(Schema::hasTable('cost_centers'))->toBeTrue();

        expect(Schema::hasColumn('cost_centers', 'id'))->toBeTrue();
        expect(Schema::hasColumn('cost_centers', 'tenant_id'))->toBeTrue();
        expect(Schema::hasColumn('cost_centers', 'site_id'))->toBeTrue();
        expect(Schema::hasColumn('cost_centers', 'code'))->toBeTrue();
        expect(Schema::hasColumn('cost_centers', 'name'))->toBeTrue();
        expect(Schema::hasColumn('cost_centers', 'activity_type'))->toBeTrue();
        expect(Schema::hasColumn('cost_centers', 'description'))->toBeTrue();
        expect(Schema::hasColumn('cost_centers', 'is_active'))->toBeTrue();
        expect(Schema::hasColumn('cost_centers', 'created_at'))->toBeTrue();
        expect(Schema::hasColumn('cost_centers', 'updated_at'))->toBeTrue();
        expect(Schema::hasColumn('cost_centers', 'deleted_at'))->toBeTrue();
    });

    test('has indexes on key columns', function (): void {
        $indexes = Schema::getIndexes('cost_centers');
        $indexColumns = collect($indexes)->pluck('columns')->flatten()->toArray();

        expect($indexColumns)->toContain('tenant_id');
        expect($indexColumns)->toContain('is_active');
        expect($indexColumns)->toContain('site_id');
    });

    test('unique constraint on site_id and code', function (): void {
        $indexes = Schema::getIndexes('cost_centers');

        $hasUniqueConstraint = collect($indexes)->contains(function ($index) {
            return $index['name'] === 'unique_site_cost_center_code'
                && in_array('site_id', $index['columns'])
                && in_array('code', $index['columns'])
                && $index['unique'] === true;
        });

        expect($hasUniqueConstraint)->toBeTrue();
    });

    test('foreign key constraint on tenant_id references tenant_keys', function (): void {
        $foreignKeys = Schema::getForeignKeys('cost_centers');

        $hasTenantForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('tenant_id', $fk['columns'])
                && $fk['foreign_table'] === 'tenant_keys';
        });

        expect($hasTenantForeignKey)->toBeTrue();
    });

    test('foreign key constraint on site_id references sites', function (): void {
        $foreignKeys = Schema::getForeignKeys('cost_centers');

        $hasSiteForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('site_id', $fk['columns'])
                && $fk['foreign_table'] === 'sites';
        });

        expect($hasSiteForeignKey)->toBeTrue();
    });

    test('cascade delete removes cost centers when tenant is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-001');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-001');
        $costCenterId = createMinimalCostCenter($tenant->id, $siteId, 'KST-001');

        expect(DB::table('cost_centers')->where('id', $costCenterId)->exists())->toBeTrue();

        $tenant->delete();

        expect(DB::table('cost_centers')->where('id', $costCenterId)->exists())->toBeFalse();
    });

    test('cascade delete removes cost centers when site is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-002');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-002');
        $costCenterId = createMinimalCostCenter($tenant->id, $siteId, 'KST-002');

        expect(DB::table('cost_centers')->where('id', $costCenterId)->exists())->toBeTrue();

        DB::table('sites')->where('id', $siteId)->delete();

        expect(DB::table('cost_centers')->where('id', $costCenterId)->exists())->toBeFalse();
    });

    test('code field stores customer internal accounting number', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-003');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-003');

        $codes = ['KST-001', 'CC-ABC-123', '9876543'];
        foreach ($codes as $code) {
            $costCenterId = createMinimalCostCenter($tenant->id, $siteId, $code, "Test for $code");
            $costCenter = DB::table('cost_centers')->where('id', $costCenterId)->first();
            expect($costCenter->code)->toBe($code);
        }
    });

    test('activity_type field for future tariff mapping', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-004');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-004');

        $costCenterId = Str::uuid()->toString();
        DB::table('cost_centers')->insert([
            'id' => $costCenterId,
            'tenant_id' => $tenant->id,
            'site_id' => $siteId,
            'code' => 'KST-004',
            'name' => 'Reception Duty',
            'activity_type' => 'Security Guard',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $costCenter = DB::table('cost_centers')->where('id', $costCenterId)->first();
        expect($costCenter->activity_type)->toBe('Security Guard');
    });

    test('activity_type is nullable', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-005');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-005');

        $costCenterId = createMinimalCostCenter($tenant->id, $siteId, 'KST-005');
        $costCenter = DB::table('cost_centers')->where('id', $costCenterId)->first();
        expect($costCenter->activity_type)->toBeNull();
    });

    test('description field is nullable', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-006');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-006');

        $costCenterId = createMinimalCostCenter($tenant->id, $siteId, 'KST-006');
        $costCenter = DB::table('cost_centers')->where('id', $costCenterId)->first();
        expect($costCenter->description)->toBeNull();
    });

    test('is_active defaults to true', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-007');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-007');

        $costCenterId = createMinimalCostCenter($tenant->id, $siteId, 'KST-007');
        $costCenter = DB::table('cost_centers')->where('id', $costCenterId)->first();
        expect($costCenter->is_active)->toBeTrue();
    });

    test('soft delete sets deleted_at timestamp', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-008');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-008');
        $costCenterId = createMinimalCostCenter($tenant->id, $siteId, 'KST-008');

        $deletedAt = now();
        DB::table('cost_centers')
            ->where('id', $costCenterId)
            ->update(['deleted_at' => $deletedAt]);

        $costCenter = DB::table('cost_centers')->where('id', $costCenterId)->first();
        expect($costCenter->deleted_at)->not->toBeNull();
    });

    test('unique constraint prevents duplicate code per site', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-009');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-009');

        createMinimalCostCenter($tenant->id, $siteId, 'KST-DUPLICATE', 'First Cost Center');
        createMinimalCostCenter($tenant->id, $siteId, 'KST-DUPLICATE', 'Second Cost Center');
    })->throws(PDOException::class);

    test('same code allowed for different sites', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-010');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $site1Id = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-010A');
        $site2Id = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-010B');

        $code = 'KST-SAME';

        createMinimalCostCenter($tenant->id, $site1Id, $code, 'Site 1 Cost Center');
        createMinimalCostCenter($tenant->id, $site2Id, $code, 'Site 2 Cost Center');

        $count = DB::table('cost_centers')->where('code', $code)->count();
        expect($count)->toBe(2);
    });

    test('cost centers are optional and table may remain empty', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        // Create customer and site but no cost centers
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-011');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-011');

        // Verify site exists
        expect(DB::table('sites')->where('id', $siteId)->exists())->toBeTrue();

        // Verify no cost centers exist for this site
        $count = DB::table('cost_centers')->where('site_id', $siteId)->count();
        expect($count)->toBe(0);
    });

    test('multiple cost centers can belong to same site', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $customerId = createCostCenterTestCustomer($tenant->id, 'KD-2025-012');
        $orgUnitId = createCostCenterTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createCostCenterTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-012');

        createMinimalCostCenter($tenant->id, $siteId, 'KST-001', 'Reception');
        createMinimalCostCenter($tenant->id, $siteId, 'KST-002', 'Warehouse');
        createMinimalCostCenter($tenant->id, $siteId, 'KST-003', 'Office');

        $count = DB::table('cost_centers')->where('site_id', $siteId)->count();
        expect($count)->toBe(3);
    });
});
