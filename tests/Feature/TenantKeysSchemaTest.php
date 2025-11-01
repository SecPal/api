<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('tenant_keys table exists', function (): void {
    expect(Schema::hasTable('tenant_keys'))->toBeTrue();
});

test('tenant_keys has correct columns', function (): void {
    expect(Schema::hasColumns('tenant_keys', [
        'id',
        'dek_wrapped',
        'dek_nonce',
        'idx_wrapped',
        'idx_nonce',
        'key_version',
        'created_at',
    ]))->toBeTrue();
});

test('tenant_keys binary columns have bytea type', function (): void {
    $columns = DB::select("
        SELECT column_name, data_type
        FROM information_schema.columns
        WHERE table_name = 'tenant_keys'
        AND column_name IN ('dek_wrapped', 'dek_nonce', 'idx_wrapped', 'idx_nonce')
    ");

    expect($columns)->toHaveCount(4);

    foreach ($columns as $column) {
        expect($column)->toBeObject();
        expect($column->data_type)->toBe('bytea'); // @phpstan-ignore property.nonObject
    }
});

test('tenant_keys key_version has integer type', function (): void {
    $column = DB::selectOne("
        SELECT data_type
        FROM information_schema.columns
        WHERE table_name = 'tenant_keys'
        AND column_name = 'key_version'
    ");

    expect($column)->toBeObject();
    expect($column->data_type)->toBe('integer'); // @phpstan-ignore property.nonObject
});
