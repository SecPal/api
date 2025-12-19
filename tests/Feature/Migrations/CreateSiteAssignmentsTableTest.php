<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
 * Helper function to create test user.
 * Uses shared tenant to avoid creating separate tenant per user.
 */
function createSiteTestUser(string $email, ?int $tenantId = null): string
{
    static $sharedTenantId = null;

    if ($tenantId === null) {
        if ($sharedTenantId === null) {
            $sharedTenantId = TenantKey::factory()->create()->id;
        }

        $tenantId = $sharedTenantId;
    }

    $userId = Str::uuid()->toString();
    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Test User',
        'email' => $email,
        'password' => Hash::make('password'),
        'tenant_id' => $tenantId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $userId;
}

/**
 * Helper function to create test organizational unit for site.
 */
function createSiteTestOrgUnit(string $tenantId, string $name): string
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
 * Helper function to create test customer for site.
 */
function createSiteTestCustomer(string $tenantId, string $customerNumber): string
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
 * Helper function to create test site.
 */
function createTestSite(string $tenantId, string $customerId, string $orgUnitId, string $siteNumber): string
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
 * Helper function to create minimal site assignment record.
 */
function createMinimalSiteAssignment(string $tenantId, string $siteId, string $userId, string $role): string
{
    $assignmentId = Str::uuid()->toString();
    DB::table('site_assignments')->insert([
        'id' => $assignmentId,
        'tenant_id' => $tenantId,
        'site_id' => $siteId,
        'user_id' => $userId,
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $assignmentId;
}

describe('CreateSiteAssignmentsTable Migration', function () {
    test('creates site_assignments table with correct columns', function (): void {
        expect(Schema::hasTable('site_assignments'))->toBeTrue();

        expect(Schema::hasColumn('site_assignments', 'id'))->toBeTrue();
        expect(Schema::hasColumn('site_assignments', 'tenant_id'))->toBeTrue();
        expect(Schema::hasColumn('site_assignments', 'site_id'))->toBeTrue();
        expect(Schema::hasColumn('site_assignments', 'user_id'))->toBeTrue();
        expect(Schema::hasColumn('site_assignments', 'role'))->toBeTrue();
        expect(Schema::hasColumn('site_assignments', 'valid_from'))->toBeTrue();
        expect(Schema::hasColumn('site_assignments', 'valid_until'))->toBeTrue();
        expect(Schema::hasColumn('site_assignments', 'notes'))->toBeTrue();
        expect(Schema::hasColumn('site_assignments', 'created_at'))->toBeTrue();
        expect(Schema::hasColumn('site_assignments', 'updated_at'))->toBeTrue();
    });

    test('has indexes on key columns', function (): void {
        $indexes = Schema::getIndexes('site_assignments');
        $indexColumns = collect($indexes)->pluck('columns')->flatten()->toArray();

        expect($indexColumns)->toContain('tenant_id');
        expect($indexColumns)->toContain('user_id');
        expect($indexColumns)->toContain('site_id');
        expect($indexColumns)->toContain('role');
        expect($indexColumns)->toContain('valid_from');
        expect($indexColumns)->toContain('valid_until');
    });

    test('unique constraint on site_id, user_id and role', function (): void {
        $indexes = Schema::getIndexes('site_assignments');

        $hasUniqueConstraint = collect($indexes)->contains(function ($index) {
            return $index['name'] === 'unique_site_user_role'
                && in_array('site_id', $index['columns'])
                && in_array('user_id', $index['columns'])
                && in_array('role', $index['columns'])
                && $index['unique'] === true;
        });

        expect($hasUniqueConstraint)->toBeTrue();
    });

    test('foreign key constraint on tenant_id references tenant_keys', function (): void {
        $foreignKeys = Schema::getForeignKeys('site_assignments');

        $hasTenantForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('tenant_id', $fk['columns'])
                && $fk['foreign_table'] === 'tenant_keys';
        });

        expect($hasTenantForeignKey)->toBeTrue();
    });

    test('foreign key constraint on site_id references sites', function (): void {
        $foreignKeys = Schema::getForeignKeys('site_assignments');

        $hasSiteForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('site_id', $fk['columns'])
                && $fk['foreign_table'] === 'sites';
        });

        expect($hasSiteForeignKey)->toBeTrue();
    });

    test('foreign key constraint on user_id references users', function (): void {
        $foreignKeys = Schema::getForeignKeys('site_assignments');

        $hasUserForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('user_id', $fk['columns'])
                && $fk['foreign_table'] === 'users';
        });

        expect($hasUserForeignKey)->toBeTrue();
    });

    test('cascade delete removes assignments when tenant is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createSiteTestUser('user@example.com');
        $customerId = createSiteTestCustomer($tenant->id, 'KD-2025-001');
        $orgUnitId = createSiteTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-001');
        $assignmentId = createMinimalSiteAssignment($tenant->id, $siteId, $userId, 'Site Manager');

        expect(DB::table('site_assignments')->where('id', $assignmentId)->exists())->toBeTrue();

        $tenant->delete();

        expect(DB::table('site_assignments')->where('id', $assignmentId)->exists())->toBeFalse();
    });

    test('cascade delete removes assignments when site is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createSiteTestUser('user@example.com');
        $customerId = createSiteTestCustomer($tenant->id, 'KD-2025-002');
        $orgUnitId = createSiteTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-002');
        $assignmentId = createMinimalSiteAssignment($tenant->id, $siteId, $userId, 'Operations Lead');

        expect(DB::table('site_assignments')->where('id', $assignmentId)->exists())->toBeTrue();

        DB::table('sites')->where('id', $siteId)->delete();

        expect(DB::table('site_assignments')->where('id', $assignmentId)->exists())->toBeFalse();
    });

    test('cascade delete removes assignments when user is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createSiteTestUser('user@example.com');
        $customerId = createSiteTestCustomer($tenant->id, 'KD-2025-003');
        $orgUnitId = createSiteTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-003');
        $assignmentId = createMinimalSiteAssignment($tenant->id, $siteId, $userId, 'Quality Manager');

        expect(DB::table('site_assignments')->where('id', $assignmentId)->exists())->toBeTrue();

        DB::table('users')->where('id', $userId)->delete();

        expect(DB::table('site_assignments')->where('id', $assignmentId)->exists())->toBeFalse();
    });

    test('role column accepts flexible tenant-specific terminology', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createSiteTestUser('user@example.com');
        $customerId = createSiteTestCustomer($tenant->id, 'KD-2025-004');
        $orgUnitId = createSiteTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-004');

        $roles = [
            'Site Manager',
            'Operations Lead',
            'Quality Manager',
            'Account Manager',
            'Standortleiter', // German terminology
        ];

        foreach ($roles as $role) {
            $assignmentId = Str::uuid()->toString();
            DB::table('site_assignments')->insert([
                'id' => $assignmentId,
                'tenant_id' => $tenant->id,
                'site_id' => $siteId,
                'user_id' => $userId,
                'role' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $assignment = DB::table('site_assignments')->where('id', $assignmentId)->first();
            expect($assignment->role)->toBe($role);
        }
    });

    test('valid_from and valid_until allow temporal assignments', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createSiteTestUser('user@example.com');
        $customerId = createSiteTestCustomer($tenant->id, 'KD-2025-005');
        $orgUnitId = createSiteTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-005');

        $assignmentId = Str::uuid()->toString();
        $validFrom = now()->subDays(30);
        $validUntil = now()->addDays(30);

        DB::table('site_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $tenant->id,
            'site_id' => $siteId,
            'user_id' => $userId,
            'role' => 'Temporary Manager',
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignment = DB::table('site_assignments')->where('id', $assignmentId)->first();
        expect($assignment->valid_from)->not->toBeNull();
        expect($assignment->valid_until)->not->toBeNull();
    });

    test('valid_until nullable allows indefinite assignments', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createSiteTestUser('user@example.com');
        $customerId = createSiteTestCustomer($tenant->id, 'KD-2025-006');
        $orgUnitId = createSiteTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-006');

        $assignmentId = Str::uuid()->toString();
        DB::table('site_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $tenant->id,
            'site_id' => $siteId,
            'user_id' => $userId,
            'role' => 'Permanent Manager',
            'valid_from' => now(),
            'valid_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignment = DB::table('site_assignments')->where('id', $assignmentId)->first();
        expect($assignment->valid_until)->toBeNull();
    });

    test('unique constraint prevents duplicate user and role per site', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createSiteTestUser('user@example.com');
        $customerId = createSiteTestCustomer($tenant->id, 'KD-2025-007');
        $orgUnitId = createSiteTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-007');

        createMinimalSiteAssignment($tenant->id, $siteId, $userId, 'Manager');
        createMinimalSiteAssignment($tenant->id, $siteId, $userId, 'Manager');
    })->throws(PDOException::class);

    test('same user can have different roles for same site', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createSiteTestUser('user@example.com');
        $customerId = createSiteTestCustomer($tenant->id, 'KD-2025-008');
        $orgUnitId = createSiteTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-008');

        createMinimalSiteAssignment($tenant->id, $siteId, $userId, 'Site Manager');
        createMinimalSiteAssignment($tenant->id, $siteId, $userId, 'Operations Lead');

        $count = DB::table('site_assignments')
            ->where('site_id', $siteId)
            ->where('user_id', $userId)
            ->count();

        expect($count)->toBe(2);
    });

    test('notes column is nullable', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createSiteTestUser('user@example.com');
        $customerId = createSiteTestCustomer($tenant->id, 'KD-2025-009');
        $orgUnitId = createSiteTestOrgUnit($tenant->id, 'Test Org Unit');
        $siteId = createTestSite($tenant->id, $customerId, $orgUnitId, 'ST-2025-009');

        $assignmentId = Str::uuid()->toString();
        DB::table('site_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $tenant->id,
            'site_id' => $siteId,
            'user_id' => $userId,
            'role' => 'Manager',
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignment = DB::table('site_assignments')->where('id', $assignmentId)->first();
        expect($assignment->notes)->toBeNull();
    });
});
