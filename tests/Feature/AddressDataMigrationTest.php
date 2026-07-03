<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('address data tables and indexes exist', function (): void {
    expect(Schema::hasTable('address_data_imports'))->toBeTrue();
    expect(Schema::hasTable('address_streets'))->toBeTrue();

    expect(Schema::hasColumns('address_data_imports', [
        'country_code',
        'source_sha256',
        'status',
        'activated_at',
        'row_count',
    ]))->toBeTrue();

    expect(Schema::hasColumns('address_streets', [
        'import_id',
        'postal_code',
        'name_search',
        'locality_search',
        'name_search_ascii',
        'locality_search_ascii',
    ]))->toBeTrue();

    $indexNames = array_map(
        static fn (object $row): string => (string) $row->indexname,
        DB::select("select indexname from pg_indexes where schemaname = current_schema() and tablename = 'address_data_imports'")
    );

    expect($indexNames)->toContain('address_data_imports_one_active_per_country');
});

test('address data tables survive even when gin_trgm_ops operator class is not on the search_path', function (): void {
    // Reproduces the Polyscope preview-schema scenario where the pg_trgm extension
    // lives in a schema outside the connection's search_path. Without the SAVEPOINT
    // around the optional gin_trgm_ops indexes, the failing CREATE INDEX would
    // abort the migration's outer transaction and silently roll back the freshly
    // created address_data_imports / address_streets tables, even though the
    // migrator records the migration as "ran".
    $isolatedSchema = 'address_migration_savepoint_test';

    DB::statement("DROP SCHEMA IF EXISTS \"{$isolatedSchema}\" CASCADE");
    DB::statement("CREATE SCHEMA \"{$isolatedSchema}\"");

    // Restrict search_path at session level so gin_trgm_ops (which lives in
    // public) is invisible.  SET LOCAL would be a no-op outside a transaction,
    // so we use SET here and undo it in the finally block.
    DB::statement("SET search_path TO \"{$isolatedSchema}\"");

    try {
        // Wrap the migration in an explicit outer transaction so that
        // DB::transaction() calls inside tryCreatePgTrgmIndexes() are issued
        // as SAVEPOINTs (nesting level > 0).  This is the production code path:
        // the Laravel migrator wraps each migration in a transaction by default.
        DB::transaction(static function () use ($isolatedSchema): void {
            $migration = require database_path('migrations/2026_05_10_140000_create_address_data_tables.php');
            $migration->up();

            $tables = collect(DB::select(
                'SELECT tablename FROM pg_tables WHERE schemaname = ? ORDER BY tablename',
                [$isolatedSchema],
            ))->pluck('tablename')->all();

            expect($tables)->toContain('address_data_imports');
            expect($tables)->toContain('address_streets');
        });
    } finally {
        DB::statement('RESET search_path');
        DB::statement("DROP SCHEMA IF EXISTS \"{$isolatedSchema}\" CASCADE");
    }
});
