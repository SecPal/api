<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
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
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * Helper function to create test user for customer assignments.
 * Reuses first available tenant (parallel-test safe).
 */
function createCustomerAssignmentTestUser(string $email, ?int $tenantId = null): string
{
    if ($tenantId === null) {
        // Use first available tenant or create one
        // Each parallel process has its own database instance
        $tenant = TenantKey::first();
        $tenantId = $tenant !== null ? $tenant->id : TenantKey::factory()->create()->id;
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
 * Helper function to create test customer for customer assignments.
 */
function createCustomerAssignmentTestCustomer(string $tenantId, string $customerNumber): string
{
    $customerId = Str::uuid()->toString();
    DB::table('customers')->insert([
        'id' => $customerId,
        'tenant_id' => $tenantId,
        'legal_entity_id' => createCustomerAssignmentLegalEntity($tenantId),
        'customer_number' => $customerNumber,
        'name' => 'Test Customer',
        'billing_address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $customerId;
}

function createCustomerAssignmentLegalEntity(string $tenantId): string
{
    $legalEntityId = Str::uuid()->toString();
    DB::table('legal_entities')->insert([
        'id' => $legalEntityId,
        'tenant_id' => $tenantId,
        'name' => 'Assignment Legal Entity',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $legalEntityId;
}

/**
 * Helper function to create minimal customer assignment record.
 */
function createMinimalCustomerAssignment(string $tenantId, string $customerId, string $userId, string $role): string
{
    $assignmentId = Str::uuid()->toString();
    DB::table('customer_assignments')->insert([
        'id' => $assignmentId,
        'tenant_id' => $tenantId,
        'customer_id' => $customerId,
        'user_id' => $userId,
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $assignmentId;
}

describe('CreateCustomerAssignmentsTable Migration', function () {
    test('creates customer_assignments table with correct columns', function (): void {
        expect(Schema::hasTable('customer_assignments'))->toBeTrue();

        expect(Schema::hasColumn('customer_assignments', 'id'))->toBeTrue();
        expect(Schema::hasColumn('customer_assignments', 'tenant_id'))->toBeTrue();
        expect(Schema::hasColumn('customer_assignments', 'customer_id'))->toBeTrue();
        expect(Schema::hasColumn('customer_assignments', 'user_id'))->toBeTrue();
        expect(Schema::hasColumn('customer_assignments', 'role'))->toBeTrue();
        expect(Schema::hasColumn('customer_assignments', 'valid_from'))->toBeTrue();
        expect(Schema::hasColumn('customer_assignments', 'valid_until'))->toBeTrue();
        expect(Schema::hasColumn('customer_assignments', 'notes'))->toBeTrue();
        expect(Schema::hasColumn('customer_assignments', 'created_at'))->toBeTrue();
        expect(Schema::hasColumn('customer_assignments', 'updated_at'))->toBeTrue();
    });

    test('has indexes on key columns', function (): void {
        $indexes = Schema::getIndexes('customer_assignments');
        $indexColumns = collect($indexes)->pluck('columns')->flatten()->toArray();

        expect($indexColumns)->toContain('tenant_id');
        expect($indexColumns)->toContain('user_id');
        expect($indexColumns)->toContain('customer_id');
        expect($indexColumns)->toContain('role');
        expect($indexColumns)->toContain('valid_from');
        expect($indexColumns)->toContain('valid_until');
    });

    test('unique constraint on customer_id, user_id and role', function (): void {
        $indexes = Schema::getIndexes('customer_assignments');

        $hasUniqueConstraint = collect($indexes)->contains(function ($index) {
            return $index['name'] === 'unique_customer_user_role'
                && in_array('customer_id', $index['columns'])
                && in_array('user_id', $index['columns'])
                && in_array('role', $index['columns'])
                && $index['unique'] === true;
        });

        expect($hasUniqueConstraint)->toBeTrue();
    });

    test('foreign key constraint on tenant_id references tenant_keys', function (): void {
        $foreignKeys = Schema::getForeignKeys('customer_assignments');

        $hasTenantForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('tenant_id', $fk['columns'])
                && $fk['foreign_table'] === 'tenant_keys';
        });

        expect($hasTenantForeignKey)->toBeTrue();
    });

    test('foreign key constraint on customer_id references customers', function (): void {
        $foreignKeys = Schema::getForeignKeys('customer_assignments');

        $hasCustomerForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('customer_id', $fk['columns'])
                && $fk['foreign_table'] === 'customers';
        });

        expect($hasCustomerForeignKey)->toBeTrue();
    });

    test('foreign key constraint on user_id references users', function (): void {
        $foreignKeys = Schema::getForeignKeys('customer_assignments');

        $hasUserForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('user_id', $fk['columns'])
                && $fk['foreign_table'] === 'users';
        });

        expect($hasUserForeignKey)->toBeTrue();
    });

    test('cascade delete removes assignments when tenant is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createCustomerAssignmentTestUser('user@example.com');
        $customerId = createCustomerAssignmentTestCustomer($tenant->id, 'KD-2025-001');
        $assignmentId = createMinimalCustomerAssignment($tenant->id, $customerId, $userId, 'Key Account Manager');

        expect(DB::table('customer_assignments')->where('id', $assignmentId)->exists())->toBeTrue();

        $tenant->delete();

        expect(DB::table('customer_assignments')->where('id', $assignmentId)->exists())->toBeFalse();
    });

    test('cascade delete removes assignments when customer is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createCustomerAssignmentTestUser('user@example.com');
        $customerId = createCustomerAssignmentTestCustomer($tenant->id, 'KD-2025-002');
        $assignmentId = createMinimalCustomerAssignment($tenant->id, $customerId, $userId, 'Sales Representative');

        expect(DB::table('customer_assignments')->where('id', $assignmentId)->exists())->toBeTrue();

        DB::table('customers')->where('id', $customerId)->delete();

        expect(DB::table('customer_assignments')->where('id', $assignmentId)->exists())->toBeFalse();
    });

    test('user delete preserves assignment history and clears the user reference', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createCustomerAssignmentTestUser('user@example.com');
        $customerId = createCustomerAssignmentTestCustomer($tenant->id, 'KD-2025-003');
        $assignmentId = createMinimalCustomerAssignment($tenant->id, $customerId, $userId, 'Support Contact');

        expect(DB::table('customer_assignments')->where('id', $assignmentId)->exists())->toBeTrue();

        DB::table('users')->where('id', $userId)->delete();

        expect(DB::table('customer_assignments')->where('id', $assignmentId)->exists())->toBeTrue()
            ->and(DB::table('customer_assignments')->where('id', $assignmentId)->value('user_id'))->toBeNull();
    });

    test('role column accepts flexible tenant-specific terminology', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createCustomerAssignmentTestUser('user@example.com');
        $customerId = createCustomerAssignmentTestCustomer($tenant->id, 'KD-2025-004');

        $roles = [
            'Key Account Manager',
            'Sales Representative',
            'Support Contact',
            'Facility Manager',
            'Hauptansprechpartner', // German terminology
        ];

        foreach ($roles as $role) {
            $assignmentId = Str::uuid()->toString();
            DB::table('customer_assignments')->insert([
                'id' => $assignmentId,
                'tenant_id' => $tenant->id,
                'customer_id' => $customerId,
                'user_id' => $userId,
                'role' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $assignment = DB::table('customer_assignments')->where('id', $assignmentId)->first();
            expect($assignment->role)->toBe($role);
        }
    });

    test('valid_from and valid_until allow temporal assignments', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createCustomerAssignmentTestUser('user@example.com');
        $customerId = createCustomerAssignmentTestCustomer($tenant->id, 'KD-2025-005');

        $assignmentId = Str::uuid()->toString();
        $validFrom = now()->subDays(30);
        $validUntil = now()->addDays(30);

        DB::table('customer_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'user_id' => $userId,
            'role' => 'Temporary Manager',
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignment = DB::table('customer_assignments')->where('id', $assignmentId)->first();
        expect($assignment->valid_from)->not->toBeNull();
        expect($assignment->valid_until)->not->toBeNull();
    });

    test('valid_until nullable allows indefinite assignments', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createCustomerAssignmentTestUser('user@example.com');
        $customerId = createCustomerAssignmentTestCustomer($tenant->id, 'KD-2025-006');

        $assignmentId = Str::uuid()->toString();
        DB::table('customer_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'user_id' => $userId,
            'role' => 'Permanent Manager',
            'valid_from' => now(),
            'valid_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignment = DB::table('customer_assignments')->where('id', $assignmentId)->first();
        expect($assignment->valid_until)->toBeNull();
    });

    test('unique constraint prevents duplicate user and role per customer', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createCustomerAssignmentTestUser('user@example.com');
        $customerId = createCustomerAssignmentTestCustomer($tenant->id, 'KD-2025-007');

        createMinimalCustomerAssignment($tenant->id, $customerId, $userId, 'Manager');
        createMinimalCustomerAssignment($tenant->id, $customerId, $userId, 'Manager');
    })->throws(PDOException::class);

    test('same user can have different roles for same customer', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createCustomerAssignmentTestUser('user@example.com');
        $customerId = createCustomerAssignmentTestCustomer($tenant->id, 'KD-2025-008');

        createMinimalCustomerAssignment($tenant->id, $customerId, $userId, 'Sales Representative');
        createMinimalCustomerAssignment($tenant->id, $customerId, $userId, 'Support Contact');

        $count = DB::table('customer_assignments')
            ->where('customer_id', $customerId)
            ->where('user_id', $userId)
            ->count();

        expect($count)->toBe(2);
    });

    test('notes column is nullable', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $userId = createCustomerAssignmentTestUser('user@example.com');
        $customerId = createCustomerAssignmentTestCustomer($tenant->id, 'KD-2025-009');

        $assignmentId = Str::uuid()->toString();
        DB::table('customer_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $tenant->id,
            'customer_id' => $customerId,
            'user_id' => $userId,
            'role' => 'Manager',
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignment = DB::table('customer_assignments')->where('id', $assignmentId)->first();
        expect($assignment->notes)->toBeNull();
    });
});
