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

test('person table exists', function () {
    expect(Schema::hasTable('person'))->toBeTrue();
});

test('person has correct columns', function () {
    expect(Schema::hasColumns('person', [
        'id',
        'tenant_id',
        'email_enc',
        'email_idx',
        'phone_enc',
        'phone_idx',
        'note_enc',
        'note_tsv',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

test('person binary columns have bytea type', function () {
    $columns = DB::select("
        SELECT column_name, data_type
        FROM information_schema.columns
        WHERE table_name = 'person'
        AND column_name IN ('email_enc', 'email_idx', 'phone_enc', 'phone_idx')
    ");

    expect($columns)->toHaveCount(4);

    foreach ($columns as $column) {
        expect($column)->toBeObject();
        expect($column->data_type)->toBe('bytea'); // @phpstan-ignore property.nonObject
    }
});

test('person note_tsv has tsvector type', function () {
    $column = DB::selectOne("
        SELECT data_type
        FROM information_schema.columns
        WHERE table_name = 'person'
        AND column_name = 'note_tsv'
    ");

    expect($column)->toBeObject();
    expect($column->data_type)->toBe('tsvector'); // @phpstan-ignore property.nonObject
});

test('person has tenant_id email_idx composite index', function () {
    $index = DB::selectOne("
        SELECT indexname
        FROM pg_indexes
        WHERE tablename = 'person'
        AND indexdef LIKE '%tenant_id%email_idx%'
    ");

    expect($index)->not->toBeNull();
});

test('person has tenant_id phone_idx composite index', function () {
    $index = DB::selectOne("
        SELECT indexname
        FROM pg_indexes
        WHERE tablename = 'person'
        AND indexdef LIKE '%tenant_id%phone_idx%'
    ");

    expect($index)->not->toBeNull();
});

test('person has GIN index on note_tsv', function () {
    $index = DB::selectOne("
        SELECT indexname, indexdef
        FROM pg_indexes
        WHERE tablename = 'person'
        AND indexname = 'person_note_tsv_idx'
    ");

    expect($index)->not->toBeNull();
    expect($index)->toBeObject();
    expect($index->indexdef)->toContain('USING gin'); // @phpstan-ignore property.nonObject
});

test('person has foreign key to tenant_keys', function () {
    $fk = DB::selectOne("
        SELECT constraint_name
        FROM information_schema.table_constraints
        WHERE table_name = 'person'
        AND constraint_type = 'FOREIGN KEY'
        AND constraint_name LIKE '%tenant_id%'
    ");

    expect($fk)->not->toBeNull();
});
