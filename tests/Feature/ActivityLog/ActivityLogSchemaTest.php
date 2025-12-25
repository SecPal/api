<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tests for Activity Log database schema extensions.
 *
 * Verifies that the activity_log table has all required custom columns
 * for SecPal's enhanced activity logging (tenant isolation, hash chain,
 * Merkle tree, OpenTimestamp integration).
 *
 * @see ADR-010 Activity Logging & Audit Trail Strategy
 */
uses()->group('feature');

/**
 * Test that activity_log table exists after migrations.
 */
test('activity log table exists', function () {
    expect(Schema::hasTable('activity_log'))->toBeTrue();
});

/**
 * Test tenant isolation columns exist.
 */
test('tenant isolation columns exist', function () {
    expect(Schema::hasColumns('activity_log', [
        'tenant_id',
        'organizational_unit_id',
    ]))->toBeTrue();
});

/**
 * Test request metadata columns exist.
 */
test('request metadata columns exist', function () {
    expect(Schema::hasColumns('activity_log', [
        'ip_address',
        'user_agent',
    ]))->toBeTrue();
});

/**
 * Test hash chain columns exist.
 */
test('hash chain columns exist', function () {
    expect(Schema::hasColumns('activity_log', [
        'previous_hash',
        'event_hash',
    ]))->toBeTrue();
});

/**
 * Test Merkle tree columns exist.
 */
test('merkle tree columns exist', function () {
    expect(Schema::hasColumns('activity_log', [
        'merkle_root',
        'merkle_batch_id',
        'merkle_proof',
    ]))->toBeTrue();

    // Validate merkle_batch_id is bigint type (timestamp-based)
    $columns = DB::select('SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?', ['activity_log', 'merkle_batch_id']);
    expect($columns[0]->data_type)->toBe('bigint');
});

/**
 * Test OpenTimestamp columns exist.
 */
test('opentimestamp columns exist', function () {
    expect(Schema::hasColumns('activity_log', [
        'ots_proof',
        'ots_submitted_at',
        'ots_confirmed_at',
    ]))->toBeTrue();
});

/**
 * Test retention metadata columns exist.
 */
test('retention metadata columns exist', function () {
    expect(Schema::hasColumns('activity_log', [
        'is_orphaned_genesis',
        'orphaned_reason',
        'orphaned_at',
    ]))->toBeTrue();
});

/**
 * Test soft delete column exists.
 */
test('soft delete column exists', function () {
    expect(Schema::hasColumn('activity_log', 'deleted_at'))->toBeTrue();
});

/**
 * Test activity_log_archive table exists.
 */
test('activity log archive table exists', function () {
    expect(Schema::hasTable('activity_log_archive'))->toBeTrue();
});

/**
 * Test archive table has required columns (hash chain continuation only).
 */
test('archive table has minimal columns', function () {
    expect(Schema::hasColumns('activity_log_archive', [
        'id',
        'tenant_id',
        'log_name',
        'created_at',
        'event_hash',
        'previous_hash',
        'merkle_root',
        'merkle_batch_id',
    ]))->toBeTrue();

    // Validate merkle_batch_id is bigint type in archive (timestamp-based)
    $columns = DB::select('SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?', ['activity_log_archive', 'merkle_batch_id']);
    expect($columns[0]->data_type)->toBe('bigint');
});

/**
 * Test archive table does NOT have GDPR-sensitive columns.
 */
test('archive table excludes sensitive columns', function () {
    // Archive table must exist first
    expect(Schema::hasTable('activity_log_archive'))->toBeTrue();

    // Archive must NOT contain personal data (GDPR compliance)
    expect(Schema::hasColumn('activity_log_archive', 'properties'))->toBeFalse();
    expect(Schema::hasColumn('activity_log_archive', 'subject_type'))->toBeFalse();
    expect(Schema::hasColumn('activity_log_archive', 'subject_id'))->toBeFalse();
    expect(Schema::hasColumn('activity_log_archive', 'causer_type'))->toBeFalse();
    expect(Schema::hasColumn('activity_log_archive', 'causer_id'))->toBeFalse();
    expect(Schema::hasColumn('activity_log_archive', 'description'))->toBeFalse();
});
