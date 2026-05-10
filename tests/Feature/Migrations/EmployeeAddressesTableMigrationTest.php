<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

test('employee_addresses table exists with expected columns and partial unique index', function (): void {
    expect(Schema::hasTable('employee_addresses'))->toBeTrue();
    expect(Schema::hasColumns('employee_addresses', [
        'id',
        'employee_id',
        'tenant_id',
        'street_enc',
        'house_number_enc',
        'postal_code_enc',
        'city_enc',
        'supplement_enc',
        'country',
        'state',
        'resided_from',
        'resided_until',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    $indexNames = array_map(
        static fn (object $row): string => (string) $row->indexname,
        DB::select("select indexname from pg_indexes where schemaname = 'public' and tablename = 'employee_addresses'")
    );
    expect($indexNames)->toContain('employee_addresses_one_current_per_employee');
});

test('employees table no longer has legacy flat address columns or address_history', function (): void {
    expect(Schema::hasColumn('employees', 'address_street_enc'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_house_number_enc'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_postal_code_enc'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_city_enc'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_supplement_enc'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_country'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_state'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_history'))->toBeFalse();
});
