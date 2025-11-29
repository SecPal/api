<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use App\Models\User;
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

describe('CreateUserInternalOrganizationalScopesTable Migration', function () {
    test('creates user_internal_organizational_scopes table with correct columns', function (): void {
        expect(Schema::hasTable('user_internal_organizational_scopes'))->toBeTrue();

        expect(Schema::hasColumn('user_internal_organizational_scopes', 'id'))->toBeTrue();
        expect(Schema::hasColumn('user_internal_organizational_scopes', 'user_id'))->toBeTrue();
        expect(Schema::hasColumn('user_internal_organizational_scopes', 'organizational_unit_id'))->toBeTrue();
        expect(Schema::hasColumn('user_internal_organizational_scopes', 'access_level'))->toBeTrue();
        expect(Schema::hasColumn('user_internal_organizational_scopes', 'include_descendants'))->toBeTrue();
        expect(Schema::hasColumn('user_internal_organizational_scopes', 'created_at'))->toBeTrue();
        expect(Schema::hasColumn('user_internal_organizational_scopes', 'updated_at'))->toBeTrue();
    });

    test('has unique constraint on user_id and organizational_unit_id', function (): void {
        $indexes = Schema::getIndexes('user_internal_organizational_scopes');

        $hasUniqueConstraint = collect($indexes)->contains(function ($index) {
            return $index['unique']
                && in_array('user_id', $index['columns'])
                && in_array('organizational_unit_id', $index['columns']);
        });

        expect($hasUniqueConstraint)->toBeTrue();
    });

    test('has indexes for efficient queries', function (): void {
        $indexes = Schema::getIndexes('user_internal_organizational_scopes');
        $indexColumns = collect($indexes)->pluck('columns')->flatten()->toArray();

        expect($indexColumns)->toContain('access_level');
        expect($indexColumns)->toContain('user_id');
        expect($indexColumns)->toContain('organizational_unit_id');
    });

    test('foreign key constraints reference users and organizational_units', function (): void {
        $foreignKeys = Schema::getForeignKeys('user_internal_organizational_scopes');

        $hasUserForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('user_id', $fk['columns'])
                && $fk['foreign_table'] === 'users';
        });

        $hasOrgUnitForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('organizational_unit_id', $fk['columns'])
                && $fk['foreign_table'] === 'organizational_units';
        });

        expect($hasUserForeignKey)->toBeTrue();
        expect($hasOrgUnitForeignKey)->toBeTrue();
    });

    test('access_level column accepts valid enum values', function (): void {
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

        $validAccessLevels = ['none', 'read', 'write', 'manage', 'admin'];

        foreach ($validAccessLevels as $accessLevel) {
            $scopeId = Str::uuid()->toString();
            $newUser = User::factory()->create();

            DB::table('user_internal_organizational_scopes')->insert([
                'id' => $scopeId,
                'user_id' => $newUser->id,
                'organizational_unit_id' => $unitId,
                'access_level' => $accessLevel,
                'include_descendants' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $scope = DB::table('user_internal_organizational_scopes')
                ->where('id', $scopeId)
                ->first();

            expect($scope->access_level)->toBe($accessLevel);
        }
    });

    test('access_level column rejects invalid enum values', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $user = User::factory()->create();
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

        DB::table('user_internal_organizational_scopes')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'organizational_unit_id' => $unitId,
            'access_level' => 'superadmin', // Invalid value
            'include_descendants' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->throws(PDOException::class);

    test('include_descendants defaults to false', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $user = User::factory()->create();
        $unitId = Str::uuid()->toString();
        $scopeId = Str::uuid()->toString();

        // Create organizational unit
        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Test Unit',
            'type' => 'department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert without specifying include_descendants
        DB::statement("
            INSERT INTO user_internal_organizational_scopes
            (id, user_id, organizational_unit_id, access_level, created_at, updated_at)
            VALUES (?, ?, ?, 'read', NOW(), NOW())
        ", [$scopeId, $user->id, $unitId]);

        $scope = DB::table('user_internal_organizational_scopes')
            ->where('id', $scopeId)
            ->first();

        expect($scope->include_descendants)->toBeFalse();
    });

    test('access_level defaults to read', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $user = User::factory()->create();
        $unitId = Str::uuid()->toString();
        $scopeId = Str::uuid()->toString();

        // Create organizational unit
        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Test Unit',
            'type' => 'department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert without specifying access_level
        DB::statement('
            INSERT INTO user_internal_organizational_scopes
            (id, user_id, organizational_unit_id, include_descendants, created_at, updated_at)
            VALUES (?, ?, ?, FALSE, NOW(), NOW())
        ', [$scopeId, $user->id, $unitId]);

        $scope = DB::table('user_internal_organizational_scopes')
            ->where('id', $scopeId)
            ->first();

        expect($scope->access_level)->toBe('read');
    });

    test('cascade delete removes scopes when user is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $user = User::factory()->create();
        $unitId = Str::uuid()->toString();
        $scopeId = Str::uuid()->toString();

        // Create organizational unit
        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Test Unit',
            'type' => 'department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create scope assignment
        DB::table('user_internal_organizational_scopes')->insert([
            'id' => $scopeId,
            'user_id' => $user->id,
            'organizational_unit_id' => $unitId,
            'access_level' => 'read',
            'include_descendants' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::table('user_internal_organizational_scopes')
            ->where('id', $scopeId)
            ->exists())->toBeTrue();

        // Delete user
        $user->delete();

        expect(DB::table('user_internal_organizational_scopes')
            ->where('id', $scopeId)
            ->exists())->toBeFalse();
    });

    test('cascade delete removes scopes when organizational unit is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $user = User::factory()->create();
        $unitId = Str::uuid()->toString();
        $scopeId = Str::uuid()->toString();

        // Create organizational unit
        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Test Unit',
            'type' => 'department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create scope assignment
        DB::table('user_internal_organizational_scopes')->insert([
            'id' => $scopeId,
            'user_id' => $user->id,
            'organizational_unit_id' => $unitId,
            'access_level' => 'admin',
            'include_descendants' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::table('user_internal_organizational_scopes')
            ->where('id', $scopeId)
            ->exists())->toBeTrue();

        // Delete organizational unit
        DB::table('organizational_units')->where('id', $unitId)->delete();

        expect(DB::table('user_internal_organizational_scopes')
            ->where('id', $scopeId)
            ->exists())->toBeFalse();
    });

    test('unique constraint prevents duplicate user-unit assignments', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $user = User::factory()->create();
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

        // First assignment should succeed
        DB::table('user_internal_organizational_scopes')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'organizational_unit_id' => $unitId,
            'access_level' => 'read',
            'include_descendants' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Second assignment with same user-unit should fail
        DB::table('user_internal_organizational_scopes')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'organizational_unit_id' => $unitId,
            'access_level' => 'admin',
            'include_descendants' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->throws(PDOException::class);

    test('same user can have scopes for different organizational units', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $user = User::factory()->create();
        $unitId1 = Str::uuid()->toString();
        $unitId2 = Str::uuid()->toString();

        // Create two organizational units
        DB::table('organizational_units')->insert([
            [
                'id' => $unitId1,
                'tenant_id' => $tenant->id,
                'name' => 'Unit 1',
                'type' => 'department',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $unitId2,
                'tenant_id' => $tenant->id,
                'name' => 'Unit 2',
                'type' => 'branch',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Same user, different units - should work
        DB::table('user_internal_organizational_scopes')->insert([
            [
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'organizational_unit_id' => $unitId1,
                'access_level' => 'read',
                'include_descendants' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'organizational_unit_id' => $unitId2,
                'access_level' => 'admin',
                'include_descendants' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $userScopes = DB::table('user_internal_organizational_scopes')
            ->where('user_id', $user->id)
            ->get();

        expect($userScopes)->toHaveCount(2);
    });

    test('include_descendants enables hierarchical access pattern', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $user = User::factory()->create();

        $companyId = Str::uuid()->toString();
        $divisionId = Str::uuid()->toString();
        $departmentId = Str::uuid()->toString();

        // Create 3-level hierarchy
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

        // Create closure table entries
        DB::table('organizational_unit_closures')->insert([
            ['ancestor_id' => $companyId, 'descendant_id' => $companyId, 'depth' => 0],
            ['ancestor_id' => $divisionId, 'descendant_id' => $divisionId, 'depth' => 0],
            ['ancestor_id' => $departmentId, 'descendant_id' => $departmentId, 'depth' => 0],
            ['ancestor_id' => $companyId, 'descendant_id' => $divisionId, 'depth' => 1],
            ['ancestor_id' => $divisionId, 'descendant_id' => $departmentId, 'depth' => 1],
            ['ancestor_id' => $companyId, 'descendant_id' => $departmentId, 'depth' => 2],
        ]);

        // User has read access to Company with include_descendants = true
        DB::table('user_internal_organizational_scopes')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'organizational_unit_id' => $companyId,
            'access_level' => 'read',
            'include_descendants' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Query: Find all units user can access via their scopes
        $accessibleUnits = DB::table('user_internal_organizational_scopes as scope')
            ->join('organizational_unit_closures as closure', function ($join) {
                $join->on('scope.organizational_unit_id', '=', 'closure.ancestor_id')
                    ->where('scope.include_descendants', '=', true);
            })
            ->where('scope.user_id', $user->id)
            ->pluck('closure.descendant_id')
            ->unique()
            ->toArray();

        expect($accessibleUnits)->toContain($companyId);
        expect($accessibleUnits)->toContain($divisionId);
        expect($accessibleUnits)->toContain($departmentId);
        expect(count($accessibleUnits))->toBe(3);
    });
});
