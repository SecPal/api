<?php

// SPDX-FileCopyrightText: 2025 SecPal <https://github.com/SecPal>
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('secret_attachments table exists', function (): void {
    expect(Schema::hasTable('secret_attachments'))->toBeTrue();
});

test('secret_attachments has correct columns', function (): void {
    expect(Schema::hasColumns('secret_attachments', [
        'id',
        'secret_id',
        'filename_enc',
        'file_size',
        'mime_type',
        'storage_path',
        'checksum_sha256',
        'uploaded_by',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

test('secret_attachments has UUID primary key', function (): void {
    $columns = Schema::getColumns('secret_attachments');
    $idColumn = collect($columns)->firstWhere('name', 'id');

    expect($idColumn)->not->toBeNull();
    expect($idColumn['type_name'])->toBe('uuid');

    // Check primary key via indexes
    $indexes = Schema::getIndexes('secret_attachments');
    $primaryKey = collect($indexes)->first(fn ($idx) => $idx['primary'] ?? false);

    expect($primaryKey)->not->toBeNull();
    expect($primaryKey['columns'])->toContain('id');
});

test('secret_attachments has correct column types', function (): void {
    $columns = Schema::getColumns('secret_attachments');
    $columnTypes = collect($columns)->mapWithKeys(fn ($col) => [$col['name'] => $col['type_name']]);

    expect($columnTypes['secret_id'])->toBe('uuid');
    expect($columnTypes['filename_enc'])->toBe('text');
    expect($columnTypes['file_size'])->toBe('int8');
    expect($columnTypes['mime_type'])->toBe('varchar');
    expect($columnTypes['storage_path'])->toBe('text');
    expect($columnTypes['checksum_sha256'])->toBe('varchar');
    expect($columnTypes['uploaded_by'])->toBe('uuid');
});

test('secret_attachments has foreign key constraints', function (): void {
    $foreignKeys = Schema::getForeignKeys('secret_attachments');
    $foreignKeyColumns = collect($foreignKeys)->pluck('columns')->flatten()->toArray();

    expect($foreignKeyColumns)->toContain('secret_id');
    expect($foreignKeyColumns)->toContain('uploaded_by');
});

test('secret_attachments has correct indexes', function (): void {
    $indexes = Schema::getIndexes('secret_attachments');
    $indexColumns = collect($indexes)->pluck('columns')->flatten()->unique()->toArray();

    expect($indexColumns)->toContain('secret_id');
});

test('secret_id foreign key cascades on deletion', function (): void {
    $foreignKeys = Schema::getForeignKeys('secret_attachments');
    $secretFk = collect($foreignKeys)->firstWhere('columns', ['secret_id']);

    expect($secretFk)->not->toBeNull();
    expect($secretFk['on_delete'])->toBe('cascade');
});
