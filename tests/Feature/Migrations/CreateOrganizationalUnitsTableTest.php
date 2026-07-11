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
        expect(Schema::hasColumn('organizational_units', 'is_legal_entity'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'is_establishment'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'is_active'))->toBeTrue();
        expect(Schema::hasColumn('organizational_units', 'is_assignable'))->toBeTrue();
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
        expect($indexColumns)->not->toContain('is_legal_entity');
        expect($indexColumns)->not->toContain('is_establishment');
    });

    test('organizational status flag columns are required booleans with false defaults', function (): void {
        $columns = collect(Schema::getColumns('organizational_units'))->keyBy('name');

        expect($columns->get('is_legal_entity'))->not->toBeNull()
            ->and($columns->get('is_legal_entity')['nullable'])->toBeFalse()
            ->and($columns->get('is_legal_entity')['default'])->toBeIn([false, 'false', 0, '0'])
            ->and($columns->get('is_establishment'))->not->toBeNull()
            ->and($columns->get('is_establishment')['nullable'])->toBeFalse()
            ->and($columns->get('is_establishment')['default'])->toBeIn([false, 'false', 0, '0']);
    });

    test('operational status flag columns are required booleans with true defaults', function (): void {
        $columns = collect(Schema::getColumns('organizational_units'))->keyBy('name');

        expect($columns->get('is_active'))->not->toBeNull()
            ->and($columns->get('is_active')['nullable'])->toBeFalse()
            ->and($columns->get('is_active')['default'])->toBeIn([true, 'true', 1, '1'])
            ->and($columns->get('is_assignable'))->not->toBeNull()
            ->and($columns->get('is_assignable')['nullable'])->toBeFalse()
            ->and($columns->get('is_assignable')['default'])->toBeIn([true, 'true', 1, '1']);
    });

    test('status flag migration assigns false to existing organizational units', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $unitId = Str::uuid()->toString();

        Schema::table('organizational_units', function ($table): void {
            $table->dropColumn(['is_legal_entity', 'is_establishment']);
        });

        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Existing Unit',
            'type' => 'company',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_10_000000_add_status_flags_to_organizational_units_table.php');
        $migration->up();

        $unit = DB::table('organizational_units')->where('id', $unitId)->first();

        expect((bool) $unit->is_legal_entity)->toBeFalse()
            ->and((bool) $unit->is_establishment)->toBeFalse();
    });

    test('operational status flag migration assigns true to existing organizational units', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);
        $unitId = Str::uuid()->toString();

        Schema::table('organizational_units', function ($table): void {
            $table->dropColumn(['is_active', 'is_assignable']);
        });

        DB::table('organizational_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenant->id,
            'name' => 'Existing Operational Unit',
            'type' => 'company',
            'is_legal_entity' => false,
            'is_establishment' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_11_000000_add_operational_status_flags_to_organizational_units_table.php');
        $migration->up();

        $unit = DB::table('organizational_units')->where('id', $unitId)->first();

        expect((bool) $unit->is_active)->toBeTrue()
            ->and((bool) $unit->is_assignable)->toBeTrue();
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
