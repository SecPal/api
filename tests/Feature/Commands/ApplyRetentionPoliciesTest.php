<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityArchive;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * ApplyRetentionPolicies Command Tests
 *
 * Validates 3-tier retention strategy per ADR-010:
 * - Level 1: Soft delete @ 1y, Hard delete @ 2y, Orphaned genesis handling
 * - Level 2: Archive @ 3y (hash only), Delete archive @ 5y
 * - Level 3: Permanent retention (no deletion)
 *
 * BewachV §21 Abs. 4 compliance: Retention calculated to end of Nth following calendar year
 * GDPR Article 5(1)(e): Storage limitation + data minimization
 *
 * @see ADR-010 Section 5: Retention & Archiving
 * @see Issue #392 PR-8: Create ActivityArchive model & retention commands
 */
test('command exists and has correct signature', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('activity:apply-retention');
});

test('level 1 soft deletes activities older than 1 year', function () {
    $tenant = TenantKey::factory()->create();

    // Create Level 1 logs at different ages
    $oldLog = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'employee_changes',
        'created_at' => now()->subYears(2)->startOfYear(), // 2 years old
    ]);

    $recentLog = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'employee_changes',
        'created_at' => now()->subMonths(6), // 6 months old
    ]);

    Artisan::call('activity:apply-retention');

    expect($oldLog->fresh()->trashed())->toBeTrue('Old log should be soft deleted')
        ->and(Activity::withTrashed()->find($oldLog->id))->not->toBeNull('Soft deleted log should exist in trash')
        ->and($recentLog->fresh())->not->toBeNull('Recent log should not be deleted');
});

test('level 1 hard deletes soft-deleted activities older than 2 years total', function () {
    $tenant = TenantKey::factory()->create();

    // Create soft-deleted Level 1 log older than 2 years
    $oldLog = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'shift_management',
        'created_at' => now()->subYears(3)->startOfYear(),
        'deleted_at' => now()->subYears(2), // Soft deleted 2 years ago
    ]);

    Artisan::call('activity:apply-retention');

    expect(Activity::withTrashed()->find($oldLog->id))->toBeNull('Soft deleted log older than 2 years should be hard deleted');
});

test('level 1 creates orphaned genesis when predecessor is deleted', function () {
    $tenant = TenantKey::factory()->create();

    // Create chain: old (to be deleted) → recent (will become orphaned genesis)
    $oldLog = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'employee_changes',
        'created_at' => now()->subYears(3),
        'event_hash' => 'old_hash',
        'previous_hash' => null,
        'deleted_at' => now()->subYears(2), // Already soft deleted
    ]);

    $recentLog = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'employee_changes',
        'created_at' => now()->subMonths(6),
        'event_hash' => 'recent_hash',
        'previous_hash' => 'old_hash', // Points to old log
    ]);

    Artisan::call('activity:apply-retention');

    $recentLog->refresh();

    expect($recentLog->is_orphaned_genesis)->toBeTrue('Log should be marked as orphaned genesis')
        ->and($recentLog->previous_hash)->toBeNull('Previous hash should be cleared')
        ->and($recentLog->orphaned_reason)->toContain('Level 1 retention policy')
        ->and($recentLog->orphaned_at)->not->toBeNull();
});

test('level 2 archives activities older than 3 years preserving only hashes', function () {
    $tenant = TenantKey::factory()->create();

    // Create Level 2 log older than 3 years with personal data (use DB insert to preserve timestamp)
    $originalId = DB::table('activity_log')->insertGetId([
        'tenant_id' => $tenant->id,
        'log_name' => 'security',
        'description' => 'Sensitive security event', // Personal data
        'properties' => json_encode(['user_ip' => '192.168.1.1']), // Personal data
        'created_at' => now()->subYears(3)->subMonths(6), // 3.5 years old (archived but not deleted)
        'updated_at' => now()->subYears(3)->subMonths(6),
        'event_hash' => 'security_hash_123',
        'previous_hash' => 'prev_hash_456',
        'merkle_root' => 'merkle_root_789',
        'merkle_batch_id' => 555,
    ]);

    // Verify activity was created correctly
    $activity = Activity::find($originalId);
    expect($activity)->not->toBeNull('Activity should exist before command runs')
        ->and($activity->log_name)->toBe('security');

    Artisan::call('activity:apply-retention');

    // Original log should be deleted
    expect(Activity::withTrashed()->find($originalId))->toBeNull('Original log should be hard deleted');

    // Archive should exist with hash only
    $archive = ActivityArchive::find($originalId);

    expect($archive)->not->toBeNull('Archive should be created')
        ->and($archive->id)->toBe($originalId)
        ->and($archive->tenant_id)->toBe($tenant->id)
        ->and($archive->log_name)->toBe('security')
        ->and($archive->event_hash)->toBe('security_hash_123')
        ->and($archive->previous_hash)->toBe('prev_hash_456')
        ->and($archive->merkle_root)->toBe('merkle_root_789')
        ->and($archive->merkle_batch_id)->toBe(555)
        ->and($archive)->not->toHaveKeys(['description', 'properties', 'subject_type', 'causer_id']);
});

test('level 2 deletes archived logs older than 5 years total', function () {
    $tenant = TenantKey::factory()->create();

    // Create archived log older than 5 years (use DB insert)
    $oldArchiveId = DB::table('activity_log_archive')->insertGetId([
        'tenant_id' => $tenant->id,
        'log_name' => 'security',
        'created_at' => now()->subYears(6)->startOfYear(),
        'event_hash' => hash('sha256', 'old'),
    ]);

    // Create archived log younger than 5 years (use DB insert)
    $recentArchiveId = DB::table('activity_log_archive')->insertGetId([
        'tenant_id' => $tenant->id,
        'log_name' => 'security',
        'created_at' => now()->subYears(4),
        'event_hash' => hash('sha256', 'recent'),
    ]);

    Artisan::call('activity:apply-retention');

    expect(ActivityArchive::find($oldArchiveId))->toBeNull('Archive older than 5 years should be deleted')
        ->and(ActivityArchive::find($recentArchiveId))->not->toBeNull('Archive younger than 5 years should be retained');
});

test('level 3 logs are never deleted - permanent retention', function () {
    $tenant = TenantKey::factory()->create();

    // Create very old Level 3 logs (10 years old)
    $oldHrLog = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'hr_access',
        'created_at' => now()->subYears(10),
    ]);

    $oldGuardBookLog = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'guard_book',
        'created_at' => now()->subYears(8),
    ]);

    Artisan::call('activity:apply-retention');

    expect($oldHrLog->fresh())->not->toBeNull('Level 3 log should never be deleted')
        ->and($oldGuardBookLog->fresh())->not->toBeNull('Level 3 log should never be deleted');
});

test('command supports dry-run mode without making changes', function () {
    $tenant = TenantKey::factory()->create();

    $oldLog = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'employee_changes',
        'created_at' => now()->subYears(2),
    ]);

    Artisan::call('activity:apply-retention', ['--dry-run' => true]);

    expect($oldLog->fresh())->not->toBeNull('Dry-run should not delete logs');
});

test('command can apply retention for specific level only', function () {
    $tenant = TenantKey::factory()->create();

    // Create Level 1 and Level 2 logs
    $level1Log = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'employee_changes',
        'created_at' => now()->subYears(2),
    ]);

    $level2Log = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'security',
        'created_at' => now()->subYears(4),
    ]);

    // Apply only Level 2 retention
    Artisan::call('activity:apply-retention', ['--level' => [2]]);

    expect($level1Log->fresh())->not->toBeNull('Level 1 log should not be affected when only Level 2 is processed')
        ->and(Activity::withTrashed()->find($level2Log->id))->toBeNull('Level 2 log should be archived');
});

test('command maintains hash chain integrity after retention', function () {
    $tenant = TenantKey::factory()->create();

    // Create chain: log1 (old, to be deleted) → log2 (recent, will be orphaned)
    $log1 = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'employee_changes',
        'created_at' => now()->subYears(3),
        'event_hash' => 'hash1',
        'previous_hash' => null,
        'deleted_at' => now()->subYears(2),
    ]);

    $log2 = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'employee_changes',
        'created_at' => now()->subMonths(6),
        'event_hash' => 'hash2',
        'previous_hash' => 'hash1',
    ]);

    Artisan::call('activity:apply-retention');

    $log2->refresh();

    expect($log2->verifyChain())->toBeTrue('Orphaned genesis should still verify as valid chain')
        ->and($log2->is_orphaned_genesis)->toBeTrue();
});

test('command handles multiple tenants correctly', function () {
    $tenant1 = TenantKey::factory()->create();
    $tenant2 = TenantKey::factory()->create();

    // Create old logs for both tenants
    $tenant1Log = Activity::factory()->create([
        'tenant_id' => $tenant1->id,
        'log_name' => 'employee_changes',
        'created_at' => now()->subYears(2),
    ]);

    $tenant2Log = Activity::factory()->create([
        'tenant_id' => $tenant2->id,
        'log_name' => 'employee_changes',
        'created_at' => now()->subYears(2),
    ]);

    Artisan::call('activity:apply-retention');

    expect($tenant1Log->fresh()->trashed())->toBeTrue('Tenant 1 log should be deleted')
        ->and($tenant2Log->fresh()->trashed())->toBeTrue('Tenant 2 log should be deleted independently');
});

test('command displays statistics after execution', function () {
    $tenant = TenantKey::factory()->create();

    Activity::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'employee_changes',
        'created_at' => now()->subYears(2),
    ]);

    Artisan::call('activity:apply-retention');
    $output = Artisan::output();

    expect($output)->toContain('Retention Statistics')
        ->and($output)->toContain('Level 1: Soft deleted')
        ->and($output)->toContain('✅ Retention policies applied successfully');
});

test('command returns success exit code on successful execution', function () {
    $exitCode = Artisan::call('activity:apply-retention');

    expect($exitCode)->toBe(0);
});

test('bewachv calendar year calculation is correct for level 1', function () {
    $tenant = TenantKey::factory()->create();

    // Event created 15 March 2023 with 1-year retention
    // Should be kept until 31 December 2024 (end of 1st following year after 2023)
    // Deletion allowed from 1 January 2025
    $log = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'log_name' => 'employee_changes',
        'created_at' => '2023-03-15 10:00:00',
    ]);

    // Test on 2024-12-31: Should NOT be deleted (still in retention period)
    $this->travelTo('2024-12-31 23:59:59');
    Artisan::call('activity:apply-retention');
    expect($log->fresh())->not->toBeNull('Log should be retained until end of year');

    // Test on 2025-01-01: Should be deleted (retention period ended)
    $this->travelTo('2025-01-01 00:00:01');
    Artisan::call('activity:apply-retention');
    expect($log->fresh()->trashed())->toBeTrue('Log should be deleted after retention period ends');
});
