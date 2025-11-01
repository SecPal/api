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

test('person table exists', function (): void {
    expect(Schema::hasTable('person'))->toBeTrue();
});

test('person has correct columns', function (): void {
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

test('person encrypted and index columns have correct text types', function (): void {
    $columns = DB::select("
        SELECT column_name, data_type
        FROM information_schema.columns
        WHERE table_name = 'person'
        AND column_name IN ('email_enc', 'email_idx', 'phone_enc', 'phone_idx')
        ORDER BY column_name
    ");

    expect($columns)->toHaveCount(4);

    // Encrypted fields (email_enc, phone_enc) use TEXT for Laravel's encrypted cast
    // Index fields (email_idx, phone_idx) use VARCHAR for base64-encoded blind indexes
    expect($columns[0]->column_name)->toBe('email_enc'); // @phpstan-ignore property.nonObject
    expect($columns[0]->data_type)->toBe('text'); // @phpstan-ignore property.nonObject

    expect($columns[1]->column_name)->toBe('email_idx'); // @phpstan-ignore property.nonObject
    expect($columns[1]->data_type)->toBe('character varying'); // @phpstan-ignore property.nonObject

    expect($columns[2]->column_name)->toBe('phone_enc'); // @phpstan-ignore property.nonObject
    expect($columns[2]->data_type)->toBe('text'); // @phpstan-ignore property.nonObject

    expect($columns[3]->column_name)->toBe('phone_idx'); // @phpstan-ignore property.nonObject
    expect($columns[3]->data_type)->toBe('character varying'); // @phpstan-ignore property.nonObject
});

test('person note_enc column exists and has text type', function (): void {
    $column = DB::selectOne("
        SELECT data_type
        FROM information_schema.columns
        WHERE table_name = 'person'
        AND column_name = 'note_enc'
    ");

    expect($column)->toBeObject();
    expect($column->data_type)->toBe('text'); // @phpstan-ignore property.nonObject
});

test('person note_tsv has tsvector type', function (): void {
    $column = DB::selectOne("
        SELECT data_type
        FROM information_schema.columns
        WHERE table_name = 'person'
        AND column_name = 'note_tsv'
    ");

    expect($column)->toBeObject();
    expect($column->data_type)->toBe('tsvector'); // @phpstan-ignore property.nonObject
});

test('person has tenant_id email_idx composite index', function (): void {
    $index = DB::selectOne("
        SELECT indexname
        FROM pg_indexes
        WHERE tablename = 'person'
        AND indexdef LIKE '%tenant_id%email_idx%'
    ");

    expect($index)->not->toBeNull();
});

test('person has tenant_id phone_idx composite index', function (): void {
    $index = DB::selectOne("
        SELECT indexname
        FROM pg_indexes
        WHERE tablename = 'person'
        AND indexdef LIKE '%tenant_id%phone_idx%'
    ");

    expect($index)->not->toBeNull();
});

test('person has GIN index on note_tsv', function (): void {
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

test('person has foreign key to tenant_keys', function (): void {
    $fk = DB::selectOne("
        SELECT constraint_name
        FROM information_schema.table_constraints
        WHERE table_name = 'person'
        AND constraint_type = 'FOREIGN KEY'
        AND constraint_name LIKE '%tenant_id%'
    ");

    expect($fk)->not->toBeNull();
});
