<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Tests for customer hierarchies database migrations (Issue #230).
 *
 * These tests verify:
 * - Table structure and constraints
 * - Closure table pattern implementation
 * - Cascading deletes
 * - Tenant isolation
 * - RBAC integration tables
 *
 * @see https://github.com/SecPal/api/issues/230
 */
beforeEach(function (): void {
    // Use proper TenantKey initialization as per project pattern
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create a tenant for testing
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('customers table', function (): void {
    test('customers table exists with required columns', function (): void {
        expect(Schema::hasTable('customers'))->toBeTrue();

        $columns = Schema::getColumnListing('customers');

        expect($columns)->toContain('id')
            ->toContain('tenant_id')
            ->toContain('managed_by_organizational_unit_id')
            ->toContain('name')
            ->toContain('customer_number')
            ->toContain('type')
            ->toContain('address')
            ->toContain('contact_email')
            ->toContain('contact_phone')
            ->toContain('metadata')
            ->toContain('created_at')
            ->toContain('updated_at')
            ->toContain('deleted_at');
    });

    test('customers can be created with valid data', function (): void {
        $customerId = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Customer',
            'customer_number' => 'CUST-001',
            'type' => 'corporate',
            'address' => 'Test Street 123',
            'contact_email' => 'test@example.com',
            'contact_phone' => '+49 123 456789',
            'metadata' => json_encode(['industry' => 'retail']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = DB::table('customers')->find($customerId);

        expect($customer)->not->toBeNull()
            ->and($customer->name)->toBe('Test Customer')
            ->and($customer->type)->toBe('corporate');
    });

    test('customer_number must be unique', function (): void {
        $uuid1 = (string) Str::uuid();
        $uuid2 = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $uuid1,
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer A',
            'customer_number' => 'UNIQUE-001',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('customers')->insert([
            'id' => $uuid2,
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer B',
            'customer_number' => 'UNIQUE-001', // Duplicate
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(Exception::class);
    });

    test('customers support all type values', function (): void {
        $types = ['corporate', 'regional', 'local', 'custom'];

        foreach ($types as $index => $type) {
            $uuid = (string) Str::uuid();
            DB::table('customers')->insert([
                'id' => $uuid,
                'tenant_id' => $this->tenant->id,
                'name' => "Customer Type {$type}",
                'customer_number' => "TYPE-{$index}",
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $customer = DB::table('customers')->find($uuid);
            expect($customer->type)->toBe($type);
        }
    });
});

describe('customer_closures table', function (): void {
    test('customer_closures table exists with required columns', function (): void {
        expect(Schema::hasTable('customer_closures'))->toBeTrue();

        $columns = Schema::getColumnListing('customer_closures');

        expect($columns)->toContain('ancestor_id')
            ->toContain('descendant_id')
            ->toContain('depth');
    });

    test('customer closure table stores self-reference with depth 0', function (): void {
        $customerId = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Self-Reference Test',
            'customer_number' => 'SELF-001',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert self-reference (depth = 0)
        DB::table('customer_closures')->insert([
            'ancestor_id' => $customerId,
            'descendant_id' => $customerId,
            'depth' => 0,
        ]);

        $closure = DB::table('customer_closures')
            ->where('ancestor_id', $customerId)
            ->where('descendant_id', $customerId)
            ->first();

        expect($closure)->not->toBeNull()
            ->and($closure->depth)->toBe(0);
    });

    test('customer hierarchy stores parent-child with depth 1', function (): void {
        $parentId = (string) Str::uuid();
        $childId = (string) Str::uuid();

        // Create parent customer
        DB::table('customers')->insert([
            'id' => $parentId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Parent Corp',
            'customer_number' => 'PARENT-001',
            'type' => 'corporate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create child customer
        DB::table('customers')->insert([
            'id' => $childId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Child Regional',
            'customer_number' => 'CHILD-001',
            'type' => 'regional',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert closure entries
        DB::table('customer_closures')->insert([
            ['ancestor_id' => $parentId, 'descendant_id' => $parentId, 'depth' => 0],
            ['ancestor_id' => $childId, 'descendant_id' => $childId, 'depth' => 0],
            ['ancestor_id' => $parentId, 'descendant_id' => $childId, 'depth' => 1],
        ]);

        $parentChild = DB::table('customer_closures')
            ->where('ancestor_id', $parentId)
            ->where('descendant_id', $childId)
            ->first();

        expect($parentChild)->not->toBeNull()
            ->and($parentChild->depth)->toBe(1);
    });

    test('cascading deletes work for customer closures', function (): void {
        $customerId = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cascade Test',
            'customer_number' => 'CASCADE-001',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customer_closures')->insert([
            'ancestor_id' => $customerId,
            'descendant_id' => $customerId,
            'depth' => 0,
        ]);

        // Delete customer
        DB::table('customers')->where('id', $customerId)->delete();

        // Closure entry should be deleted
        $closure = DB::table('customer_closures')
            ->where('ancestor_id', $customerId)
            ->first();

        expect($closure)->toBeNull();
    });

    test('unique constraint prevents duplicate ancestor-descendant pairs', function (): void {
        $customerId = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Unique Test',
            'customer_number' => 'UNIQUE-CLOSURE-001',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customer_closures')->insert([
            'ancestor_id' => $customerId,
            'descendant_id' => $customerId,
            'depth' => 0,
        ]);

        // Duplicate should fail
        expect(fn () => DB::table('customer_closures')->insert([
            'ancestor_id' => $customerId,
            'descendant_id' => $customerId,
            'depth' => 0,
        ]))->toThrow(Exception::class);
    });
});

describe('objects table', function (): void {
    test('objects table exists with required columns', function (): void {
        expect(Schema::hasTable('objects'))->toBeTrue();

        $columns = Schema::getColumnListing('objects');

        expect($columns)->toContain('id')
            ->toContain('tenant_id')
            ->toContain('customer_id')
            ->toContain('object_number')
            ->toContain('name')
            ->toContain('address')
            ->toContain('gps_coordinates')
            ->toContain('metadata')
            ->toContain('created_at')
            ->toContain('updated_at')
            ->toContain('deleted_at');
    });

    test('objects can be created with GPS coordinates', function (): void {
        $customerId = (string) Str::uuid();
        $objectId = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Object Test Customer',
            'customer_number' => 'OBJ-CUST-001',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('objects')->insert([
            'id' => $objectId,
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customerId,
            'object_number' => 'OBJ-001',
            'name' => 'Test Building',
            'address' => 'Test Address 123',
            'gps_coordinates' => json_encode(['lat' => 52.520, 'lon' => 13.405]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $object = DB::table('objects')->find($objectId);

        expect($object)->not->toBeNull()
            ->and($object->name)->toBe('Test Building');

        $coords = json_decode($object->gps_coordinates, true);
        expect($coords['lat'])->toBe(52.520)
            ->and($coords['lon'])->toBe(13.405);
    });

    test('object_number is unique per tenant', function (): void {
        $customerId = (string) Str::uuid();
        $objectId1 = (string) Str::uuid();
        $objectId2 = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Unique Object Customer',
            'customer_number' => 'UNIQUE-OBJ-CUST',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('objects')->insert([
            'id' => $objectId1,
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customerId,
            'object_number' => 'UNIQUE-OBJ-001',
            'name' => 'Object A',
            'address' => 'Address A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('objects')->insert([
            'id' => $objectId2,
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customerId,
            'object_number' => 'UNIQUE-OBJ-001', // Duplicate in same tenant
            'name' => 'Object B',
            'address' => 'Address B',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(Exception::class);
    });

    test('cascading deletes work for objects when customer deleted', function (): void {
        $customerId = (string) Str::uuid();
        $objectId = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cascade Object Customer',
            'customer_number' => 'CASCADE-OBJ-CUST',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('objects')->insert([
            'id' => $objectId,
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customerId,
            'object_number' => 'CASCADE-OBJ-001',
            'name' => 'Cascade Object',
            'address' => 'Cascade Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Delete customer
        DB::table('customers')->where('id', $customerId)->delete();

        // Object should be deleted
        $object = DB::table('objects')->find($objectId);
        expect($object)->toBeNull();
    });
});

describe('object_areas table', function (): void {
    test('object_areas table exists with required columns', function (): void {
        expect(Schema::hasTable('object_areas'))->toBeTrue();

        $columns = Schema::getColumnListing('object_areas');

        expect($columns)->toContain('id')
            ->toContain('tenant_id')
            ->toContain('object_id')
            ->toContain('name')
            ->toContain('description')
            ->toContain('requires_separate_guard_book')
            ->toContain('gps_boundaries')
            ->toContain('metadata')
            ->toContain('created_at')
            ->toContain('updated_at')
            ->toContain('deleted_at');
    });

    test('object_areas can be created with separate guard book flag', function (): void {
        $customerId = (string) Str::uuid();
        $objectId = (string) Str::uuid();
        $areaId = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Area Test Customer',
            'customer_number' => 'AREA-CUST-001',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('objects')->insert([
            'id' => $objectId,
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customerId,
            'object_number' => 'AREA-OBJ-001',
            'name' => 'Airport',
            'address' => 'Airport Road 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('object_areas')->insert([
            'id' => $areaId,
            'tenant_id' => $this->tenant->id,
            'object_id' => $objectId,
            'name' => 'Terminal 1',
            'description' => 'Main terminal building',
            'requires_separate_guard_book' => true,
            'gps_boundaries' => json_encode([
                ['lat' => 52.520, 'lon' => 13.400],
                ['lat' => 52.521, 'lon' => 13.401],
                ['lat' => 52.522, 'lon' => 13.402],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $area = DB::table('object_areas')->find($areaId);

        expect($area)->not->toBeNull()
            ->and($area->name)->toBe('Terminal 1')
            ->and($area->requires_separate_guard_book)->toBe(true);
    });

    test('cascading deletes work for object_areas when object deleted', function (): void {
        $customerId = (string) Str::uuid();
        $objectId = (string) Str::uuid();
        $areaId = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cascade Area Customer',
            'customer_number' => 'CASCADE-AREA-CUST',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('objects')->insert([
            'id' => $objectId,
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customerId,
            'object_number' => 'CASCADE-AREA-OBJ',
            'name' => 'Cascade Object',
            'address' => 'Cascade Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('object_areas')->insert([
            'id' => $areaId,
            'tenant_id' => $this->tenant->id,
            'object_id' => $objectId,
            'name' => 'Cascade Area',
            'requires_separate_guard_book' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Delete object
        DB::table('objects')->where('id', $objectId)->delete();

        // Area should be deleted
        $area = DB::table('object_areas')->find($areaId);
        expect($area)->toBeNull();
    });
});

describe('customer_user_accesses table', function (): void {
    test('customer_user_accesses table exists with required columns', function (): void {
        expect(Schema::hasTable('customer_user_accesses'))->toBeTrue();

        $columns = Schema::getColumnListing('customer_user_accesses');

        expect($columns)->toContain('id')
            ->toContain('tenant_id')
            ->toContain('user_id')
            ->toContain('customer_id')
            ->toContain('access_level')
            ->toContain('include_descendants')
            ->toContain('created_at')
            ->toContain('updated_at');
    });

    test('customer user access can be created with access levels', function (): void {
        $customerId = (string) Str::uuid();
        $accessId = (string) Str::uuid();

        // Create user using factory
        $user = User::factory()->create();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Access Test Customer',
            'customer_number' => 'ACCESS-CUST-001',
            'type' => 'corporate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customer_user_accesses')->insert([
            'id' => $accessId,
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'customer_id' => $customerId,
            'access_level' => 'corporate_wide',
            'include_descendants' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $access = DB::table('customer_user_accesses')->find($accessId);

        expect($access)->not->toBeNull()
            ->and($access->access_level)->toBe('corporate_wide')
            ->and($access->include_descendants)->toBe(true);
    });

    test('customer user access supports all access levels', function (): void {
        $levels = ['corporate_wide', 'regional', 'local'];

        foreach ($levels as $index => $level) {
            $customerId = (string) Str::uuid();
            $accessId = (string) Str::uuid();

            $user = User::factory()->create();

            DB::table('customers')->insert([
                'id' => $customerId,
                'tenant_id' => $this->tenant->id,
                'name' => "Level {$level} Customer",
                'customer_number' => "LEVEL-{$index}",
                'type' => 'local',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('customer_user_accesses')->insert([
                'id' => $accessId,
                'tenant_id' => $this->tenant->id,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'access_level' => $level,
                'include_descendants' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $access = DB::table('customer_user_accesses')->find($accessId);
            expect($access->access_level)->toBe($level);
        }
    });

    test('unique constraint prevents duplicate user-customer pairs', function (): void {
        $customerId = (string) Str::uuid();

        $user = User::factory()->create();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Duplicate Access Customer',
            'customer_number' => 'DUP-ACCESS-CUST',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customer_user_accesses')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'customer_id' => $customerId,
            'access_level' => 'local',
            'include_descendants' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('customer_user_accesses')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'customer_id' => $customerId,
            'access_level' => 'corporate_wide',
            'include_descendants' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(Exception::class);
    });

    test('cascading deletes work when user deleted', function (): void {
        $customerId = (string) Str::uuid();
        $accessId = (string) Str::uuid();

        $user = User::factory()->create();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cascade Access Customer',
            'customer_number' => 'CASCADE-ACCESS-CUST',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customer_user_accesses')->insert([
            'id' => $accessId,
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'customer_id' => $customerId,
            'access_level' => 'local',
            'include_descendants' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Delete user
        DB::table('users')->where('id', $user->id)->delete();

        // Access should be deleted
        $access = DB::table('customer_user_accesses')->find($accessId);
        expect($access)->toBeNull();
    });

    test('cascading deletes work when customer deleted', function (): void {
        $customerId = (string) Str::uuid();
        $accessId = (string) Str::uuid();

        $user = User::factory()->create();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cascade Customer Access Customer',
            'customer_number' => 'CASCADE-CUST-ACCESS-CUST',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customer_user_accesses')->insert([
            'id' => $accessId,
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'customer_id' => $customerId,
            'access_level' => 'local',
            'include_descendants' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Delete customer
        DB::table('customers')->where('id', $customerId)->delete();

        // Access should be deleted
        $access = DB::table('customer_user_accesses')->find($accessId);
        expect($access)->toBeNull();
    });
});

describe('customer_user_object_accesses table', function (): void {
    test('customer_user_object_accesses table exists with required columns', function (): void {
        expect(Schema::hasTable('customer_user_object_accesses'))->toBeTrue();

        $columns = Schema::getColumnListing('customer_user_object_accesses');

        expect($columns)->toContain('id')
            ->toContain('tenant_id')
            ->toContain('user_id')
            ->toContain('object_id')
            ->toContain('allowed_actions')
            ->toContain('created_at')
            ->toContain('updated_at');
    });

    test('customer user object access stores allowed_actions as jsonb', function (): void {
        $customerId = (string) Str::uuid();
        $objectId = (string) Str::uuid();
        $accessId = (string) Str::uuid();

        $user = User::factory()->create();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Object Access Customer',
            'customer_number' => 'OBJ-ACCESS-CUST',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('objects')->insert([
            'id' => $objectId,
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customerId,
            'object_number' => 'OBJ-ACCESS-OBJ',
            'name' => 'Object Access Object',
            'address' => 'Object Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $allowedActions = ['read_guard_book', 'read_reports', 'export_reports'];

        DB::table('customer_user_object_accesses')->insert([
            'id' => $accessId,
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'object_id' => $objectId,
            'allowed_actions' => json_encode($allowedActions),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $access = DB::table('customer_user_object_accesses')->find($accessId);

        expect($access)->not->toBeNull();

        $storedActions = json_decode($access->allowed_actions, true);
        expect($storedActions)->toBe($allowedActions);
    });

    test('unique constraint prevents duplicate user-object pairs', function (): void {
        $customerId = (string) Str::uuid();
        $objectId = (string) Str::uuid();

        $user = User::factory()->create();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Dup Object Access Customer',
            'customer_number' => 'DUP-OBJ-ACCESS-CUST',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('objects')->insert([
            'id' => $objectId,
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customerId,
            'object_number' => 'DUP-OBJ-ACCESS-OBJ',
            'name' => 'Dup Object',
            'address' => 'Dup Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customer_user_object_accesses')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'object_id' => $objectId,
            'allowed_actions' => json_encode(['read_guard_book']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('customer_user_object_accesses')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'object_id' => $objectId, // Duplicate
            'allowed_actions' => json_encode(['read_reports']),
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(Exception::class);
    });

    test('cascading deletes work when object deleted', function (): void {
        $customerId = (string) Str::uuid();
        $objectId = (string) Str::uuid();
        $accessId = (string) Str::uuid();

        $user = User::factory()->create();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cascade Obj Customer',
            'customer_number' => 'CASCADE-OBJ-CUST',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('objects')->insert([
            'id' => $objectId,
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customerId,
            'object_number' => 'CASCADE-OBJ',
            'name' => 'Cascade Object',
            'address' => 'Cascade Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customer_user_object_accesses')->insert([
            'id' => $accessId,
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'object_id' => $objectId,
            'allowed_actions' => json_encode(['read_guard_book']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Delete object
        DB::table('objects')->where('id', $objectId)->delete();

        // Access should be deleted
        $access = DB::table('customer_user_object_accesses')->find($accessId);
        expect($access)->toBeNull();
    });

    test('cascading deletes work when user deleted', function (): void {
        $customerId = (string) Str::uuid();
        $objectId = (string) Str::uuid();
        $accessId = (string) Str::uuid();

        $user = User::factory()->create();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cascade User Obj Customer',
            'customer_number' => 'CASCADE-USER-OBJ-CUST',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('objects')->insert([
            'id' => $objectId,
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customerId,
            'object_number' => 'CASCADE-USER-OBJ',
            'name' => 'Cascade User Object',
            'address' => 'Cascade User Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customer_user_object_accesses')->insert([
            'id' => $accessId,
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'object_id' => $objectId,
            'allowed_actions' => json_encode(['read_guard_book']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Delete user
        DB::table('users')->where('id', $user->id)->delete();

        // Access should be deleted
        $access = DB::table('customer_user_object_accesses')->find($accessId);
        expect($access)->toBeNull();
    });
});

describe('customer hierarchy independence', function (): void {
    test('customer hierarchy is independent from organizational units', function (): void {
        // Create organizational unit
        $orgUnitId = (string) Str::uuid();
        DB::table('organizational_units')->insert([
            'id' => $orgUnitId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Berlin',
            'type' => 'branch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create customer managed by the org unit
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $this->tenant->id,
            'managed_by_organizational_unit_id' => $orgUnitId,
            'name' => 'Managed Customer',
            'customer_number' => 'MANAGED-001',
            'type' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = DB::table('customers')->find($customerId);

        // Customer has link to org unit, but hierarchies are separate
        expect($customer->managed_by_organizational_unit_id)->toBe($orgUnitId);

        // Verify the tables are separate (no FK from org_unit_closures to customers)
        expect(Schema::hasColumn('organizational_unit_closures', 'customer_id'))->toBeFalse();
        expect(Schema::hasColumn('customer_closures', 'organizational_unit_id'))->toBeFalse();
    });
});

describe('tenant isolation', function (): void {
    test('tenant isolation is enforced via tenant_id', function (): void {
        // All tables should have tenant_id column
        $tables = [
            'customers',
            'objects',
            'object_areas',
            'customer_user_accesses',
            'customer_user_object_accesses',
        ];

        foreach ($tables as $table) {
            expect(Schema::hasColumn($table, 'tenant_id'))->toBeTrue(
                "Table {$table} should have tenant_id column"
            );
        }
    });
});
