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
        DB::select("select indexname from pg_indexes where schemaname = 'public' and tablename = 'address_data_imports'")
    );

    expect($indexNames)->toContain('address_data_imports_one_active_per_country');
});
