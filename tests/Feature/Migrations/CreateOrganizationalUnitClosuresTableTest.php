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

describe('CreateOrganizationalUnitClosuresTable Migration', function () {
    test('creates organizational_unit_closures table with correct columns', function (): void {
        expect(Schema::hasTable('organizational_unit_closures'))->toBeTrue();

        expect(Schema::hasColumn('organizational_unit_closures', 'ancestor_id'))->toBeTrue();
        expect(Schema::hasColumn('organizational_unit_closures', 'descendant_id'))->toBeTrue();
        expect(Schema::hasColumn('organizational_unit_closures', 'depth'))->toBeTrue();
    });

    test('has composite primary key on ancestor_id and descendant_id', function (): void {
        $indexes = Schema::getIndexes('organizational_unit_closures');

        $hasPrimaryKey = collect($indexes)->contains(function ($index) {
            return $index['primary']
                && in_array('ancestor_id', $index['columns'])
                && in_array('descendant_id', $index['columns']);
        });

        expect($hasPrimaryKey)->toBeTrue();
    });

    test('has indexes for efficient hierarchical queries', function (): void {
        $indexes = Schema::getIndexes('organizational_unit_closures');
        $indexColumns = collect($indexes)->pluck('columns')->flatten()->toArray();

        expect($indexColumns)->toContain('depth');
        expect($indexColumns)->toContain('ancestor_id');
        expect($indexColumns)->toContain('descendant_id');
    });

    test('foreign key constraints reference organizational_units', function (): void {
        $foreignKeys = Schema::getForeignKeys('organizational_unit_closures');

        $hasAncestorForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('ancestor_id', $fk['columns'])
                && $fk['foreign_table'] === 'organizational_units';
        });

        $hasDescendantForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('descendant_id', $fk['columns'])
                && $fk['foreign_table'] === 'organizational_units';
        });

        expect($hasAncestorForeignKey)->toBeTrue();
        expect($hasDescendantForeignKey)->toBeTrue();
    });

    test('self-reference entry with depth 0 is valid', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $unitId = Str::uuid()->toString();

        // Create organizational unit
        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Test Unit',
            'type' => 'department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Self-reference with depth 0 should work
        DB::table('organizational_unit_closures')->insert([
            'ancestor_id' => $unitId,
            'descendant_id' => $unitId,
            'depth' => 0,
        ]);

        $closure = DB::table('organizational_unit_closures')
            ->where('ancestor_id', $unitId)
            ->where('descendant_id', $unitId)
            ->first();

        expect($closure)->not->toBeNull();
        expect($closure->depth)->toBe(0);
    });

    test('self-reference entry with depth greater than 0 is rejected', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $unitId = Str::uuid()->toString();

        // Create organizational unit
        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Test Unit',
            'type' => 'department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Self-reference with depth > 0 should fail due to CHECK constraint
        DB::table('organizational_unit_closures')->insert([
            'ancestor_id' => $unitId,
            'descendant_id' => $unitId,
            'depth' => 1,
        ]);
    })->throws(PDOException::class);

    test('parent-child relationship with depth 1 is valid', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $parentId = Str::uuid()->toString();
        $childId = Str::uuid()->toString();

        // Create parent unit
        DB::table('organizational_units')->insert([
            'id' => $parentId,
            'tenant_id' => $tenant->id,
            'name' => 'Parent Unit',
            'type' => 'company',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create child unit
        DB::table('organizational_units')->insert([
            'id' => $childId,
            'tenant_id' => $tenant->id,
            'name' => 'Child Unit',
            'type' => 'department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Self-references
        DB::table('organizational_unit_closures')->insert([
            ['ancestor_id' => $parentId, 'descendant_id' => $parentId, 'depth' => 0],
            ['ancestor_id' => $childId, 'descendant_id' => $childId, 'depth' => 0],
        ]);

        // Parent-child relationship
        DB::table('organizational_unit_closures')->insert([
            'ancestor_id' => $parentId,
            'descendant_id' => $childId,
            'depth' => 1,
        ]);

        $closure = DB::table('organizational_unit_closures')
            ->where('ancestor_id', $parentId)
            ->where('descendant_id', $childId)
            ->first();

        expect($closure)->not->toBeNull();
        expect($closure->depth)->toBe(1);
    });

    test('cascade delete removes closures when organizational unit is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $parentId = Str::uuid()->toString();
        $childId = Str::uuid()->toString();

        // Create units
        DB::table('organizational_units')->insert([
            [
                'id' => $parentId,
                'tenant_id' => $tenant->id,
                'name' => 'Parent Unit',
                'type' => 'company',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $childId,
                'tenant_id' => $tenant->id,
                'name' => 'Child Unit',
                'type' => 'department',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Create closure entries
        DB::table('organizational_unit_closures')->insert([
            ['ancestor_id' => $parentId, 'descendant_id' => $parentId, 'depth' => 0],
            ['ancestor_id' => $childId, 'descendant_id' => $childId, 'depth' => 0],
            ['ancestor_id' => $parentId, 'descendant_id' => $childId, 'depth' => 1],
        ]);

        expect(DB::table('organizational_unit_closures')->count())->toBe(3);

        // Delete parent unit - should cascade delete all closure entries referencing it
        DB::table('organizational_units')->where('id', $parentId)->delete();

        // Parent's self-reference and parent->child relationship should be deleted
        expect(DB::table('organizational_unit_closures')
            ->where('ancestor_id', $parentId)
            ->exists())->toBeFalse();

        // Child's self-reference should still exist
        expect(DB::table('organizational_unit_closures')
            ->where('ancestor_id', $childId)
            ->where('descendant_id', $childId)
            ->exists())->toBeTrue();
    });

    test('depth column is unsigned integer', function (): void {
        $columns = Schema::getColumns('organizational_unit_closures');
        $depthColumn = collect($columns)->firstWhere('name', 'depth');

        // PostgreSQL int4 is used for unsignedInteger
        expect($depthColumn['type_name'])->toBe('int4');
    });

    test('get all descendants query pattern works efficiently', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $companyId = Str::uuid()->toString();
        $divisionId = Str::uuid()->toString();
        $departmentId = Str::uuid()->toString();

        // Create 3-level hierarchy: Company -> Division -> Department
        DB::table('organizational_units')->insert([
            [
                'id' => $companyId,
                'tenant_id' => $tenant->id,
                'name' => 'Company',
                'type' => 'company',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $divisionId,
                'tenant_id' => $tenant->id,
                'name' => 'Division',
                'type' => 'division',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $departmentId,
                'tenant_id' => $tenant->id,
                'name' => 'Department',
                'type' => 'department',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Create full closure table entries
        DB::table('organizational_unit_closures')->insert([
            // Self-references
            ['ancestor_id' => $companyId, 'descendant_id' => $companyId, 'depth' => 0],
            ['ancestor_id' => $divisionId, 'descendant_id' => $divisionId, 'depth' => 0],
            ['ancestor_id' => $departmentId, 'descendant_id' => $departmentId, 'depth' => 0],
            // Direct children
            ['ancestor_id' => $companyId, 'descendant_id' => $divisionId, 'depth' => 1],
            ['ancestor_id' => $divisionId, 'descendant_id' => $departmentId, 'depth' => 1],
            // Transitive closure
            ['ancestor_id' => $companyId, 'descendant_id' => $departmentId, 'depth' => 2],
        ]);

        // Query: Get all descendants of Company (including itself)
        $descendants = DB::table('organizational_unit_closures')
            ->where('ancestor_id', $companyId)
            ->pluck('descendant_id')
            ->toArray();

        expect($descendants)->toContain($companyId);
        expect($descendants)->toContain($divisionId);
        expect($descendants)->toContain($departmentId);
        expect(count($descendants))->toBe(3);

        // Query: Get all ancestors of Department (including itself)
        $ancestors = DB::table('organizational_unit_closures')
            ->where('descendant_id', $departmentId)
            ->pluck('ancestor_id')
            ->toArray();

        expect($ancestors)->toContain($companyId);
        expect($ancestors)->toContain($divisionId);
        expect($ancestors)->toContain($departmentId);
        expect(count($ancestors))->toBe(3);

        // Query: Get only direct children of Company (depth = 1)
        $directChildren = DB::table('organizational_unit_closures')
            ->where('ancestor_id', $companyId)
            ->where('depth', 1)
            ->pluck('descendant_id')
            ->toArray();

        expect($directChildren)->toBe([$divisionId]);
    });
});
