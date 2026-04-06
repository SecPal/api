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
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('CreateOrganizationalUnitsTable Migration', function () {
    test('creates organizational_units table with correct columns', function (): void {
        expect(Schema::hasTable('organizational_units'))->toBeTrue();

        expect(Schema::hasColumn('organizational_units', 'id'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'tenant_id'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'type'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'name'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'custom_type_name'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'description'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'metadata'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'created_at'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'updated_at'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'deleted_at'))->toBeTrue();
    });

    test('has indexes on key columns', function (): void {
        $indexes = Schema::getIndexes('organizational_units');
        $indexColumns = collect($indexes)->pluck('columns')->flatten()->toArray();

        expect($indexColumns)->toContain('tenant_id');
        expect($indexColumns)->toContain('type');
        expect($indexColumns)->toContain('deleted_at');
    });

    test('type column accepts valid enum values', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $validTypes = [
            'holding',
            'company',
            'region',
            'branch',
            'division',
            'department',
            'custom',
        ];

        foreach ($validTypes as $type) {
            $id = Str::uuid()->toString();
            DB::table('organizational_units')->insert([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'name' => "Test Unit {$type}",
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $unit = DB::table('organizational_units')->where('id', $id)->first();
            expect($unit->type)->toBe($type);
        }
    });

    test('type column rejects invalid enum values', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        DB::table('organizational_units')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'name' => 'Invalid Type Unit',
            'type' => 'invalid_type',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->throws(PDOException::class);

    test('foreign key constraint on tenant_id references tenant_keys', function (): void {
        $foreignKeys = Schema::getForeignKeys('organizational_units');

        $hasTenantForeignKey = collect($foreignKeys)->contains(function ($fk) {
            return in_array('tenant_id', $fk['columns'])
                && $fk['foreign_table'] === 'tenant_keys';
        });

        expect($hasTenantForeignKey)->toBeTrue();
    });

    test('cascade delete removes organizational units when tenant is deleted', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $unitId = Str::uuid()->toString();

        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Test Unit',
            'type' => 'department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::table('organizational_units')->where('id', $unitId)->exists())->toBeTrue();

        $tenant->delete();

        expect(DB::table('organizational_units')->where('id', $unitId)->exists())->toBeFalse();
    });

    test('metadata column accepts valid JSON', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $unitId = Str::uuid()->toString();

        $metadata = [
            'address' => '123 Main Street',
            'phone' => '+49 30 12345678',
            'manager' => 'John Doe',
        ];

        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Test Unit',
            'type' => 'branch',
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unit = DB::table('organizational_units')->where('id', $unitId)->first();
        $decodedMetadata = json_decode($unit->metadata, true);
        expect($decodedMetadata)->toHaveKey('address', '123 Main Street');
        expect($decodedMetadata)->toHaveKey('phone', '+49 30 12345678');
        expect($decodedMetadata)->toHaveKey('manager', 'John Doe');
    });

    test('soft delete sets deleted_at timestamp', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $unitId = Str::uuid()->toString();

        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Test Unit',
            'type' => 'department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deletedAt = now();
        DB::table('organizational_units')
            ->where('id', $unitId)
            ->update(['deleted_at' => $deletedAt]);

        $unit = DB::table('organizational_units')->where('id', $unitId)->first();
        expect($unit->deleted_at)->not->toBeNull();
    });

    test('custom type uses custom_type_name field', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $unitId = Str::uuid()->toString();

        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Special Unit',
            'type' => 'custom',
            'custom_type_name' => 'Event Security Task Force',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unit = DB::table('organizational_units')->where('id', $unitId)->first();
        expect($unit->type)->toBe('custom');
        expect($unit->custom_type_name)->toBe('Event Security Task Force');
    });
});
