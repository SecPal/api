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
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Test GDPR-compliant hard delete and ActivityArchive integration.
 *
 * Tests Issue #443: Phase 2 - Hard Delete & Archive
 *
 * Option A: Direct Archiving (GDPR-compliant)
 * - No soft delete grace period (legally questionable)
 * - Direct archive + hard delete when retention expires
 * - Personal data immediately deleted ("unverzüglich" per GDPR Art. 17)
 *
 * Coverage:
 * - Direct archive + hard delete (default behavior)
 * - ActivityArchive contains ONLY hashes (no personal data)
 * - Orphaned genesis marking with archived predecessors
 * - Statistics tracking
 * - Scheduler configuration
 *
 * @see Issue #443 Phase 2: GDPR-Compliant Hard Delete & ActivityArchive Integration
 */
beforeEach(function () {
    $this->tenant = TenantKey::factory()->create();
});

test('it archives and hard deletes expired logs directly', function () {
    // Create log that expired (> 3 years old, retention period ended)
    $log = Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'log_name' => 'default',
            'created_at' => Carbon::now()->subYears(4)->startOfYear(), // 4 years ago
            'description' => 'Sensitive personal data that must be deleted',
            'properties' => ['user_action' => 'logged_in', 'ip' => '192.168.1.1'],
        ]);

    // Hashes are generated on save
    $log->refresh();
    $originalEventHash = $log->event_hash;
    $originalPreviousHash = $log->previous_hash;

    // Act: Run retention policy (direct archive + delete, no soft delete)
    $this->artisan('activity:apply-retention')
        ->assertSuccessful();

    // Assert: Log is hard deleted (GDPR Art. 17 - unverzüglich)
    $this->assertDatabaseMissing('activity_log', [
        'id' => $log->id,
    ]);

    // Assert: Archive created with ONLY hashes
    $archive = ActivityArchive::find($log->id);
    expect($archive)->not->toBeNull();
    expect($archive->tenant_id)->toBe($this->tenant->id);
    expect($archive->log_name)->toBe('default');
    expect($archive->event_hash)->toBe($originalEventHash);
    expect($archive->previous_hash)->toBe($originalPreviousHash);

    // Assert: NO personal data in archive (GDPR Art. 5(1)(e) - data minimization)
    expect($archive->description ?? null)->toBeNull(); // Column shouldn't exist
    expect($archive->properties ?? null)->toBeNull();  // Column shouldn't exist
    expect($archive->subject_id ?? null)->toBeNull();  // Column shouldn't exist
});

test('it creates orphaned genesis for successor when archiving predecessor', function () {
    // Create chain: Log A (expired) → Log B (active, within retention)
    $logA = Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'log_name' => 'default',
            'created_at' => Carbon::now()->subYears(4)->startOfYear(),
            'event_hash' => 'hash_a',
            'previous_hash' => null,
        ]);

    $logB = Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'log_name' => 'default',
            'created_at' => Carbon::now()->subYear(),
            'event_hash' => 'hash_b',
            'previous_hash' => 'hash_a', // Points to Log A
        ]);

    // Act: Direct archive + hard delete (no soft delete intermediate)
    $this->artisan('activity:apply-retention')
        ->assertSuccessful();

    // Assert: Log A is archived and hard deleted
    $this->assertDatabaseMissing('activity_log', ['id' => $logA->id]);
    $this->assertDatabaseHas('activity_log_archive', ['id' => $logA->id]);

    // Assert: Log B is marked as orphaned genesis
    $logB->refresh();
    expect($logB->is_orphaned_genesis)->toBeTrue();
    expect($logB->previous_hash)->toBeNull();
    expect($logB->orphaned_reason)->toContain('Predecessor archived');
    expect($logB->orphaned_at)->not->toBeNull();
});

// Test removed: SoftDeletes no longer exists (Issue #447)
// Previous test: 'it processes both active and soft deleted logs'
// Reason: Activity model uses hard delete only (GDPR Art. 17 compliance)

test('it tracks statistics for archived and hard deleted logs', function () {
    // Create 3 expired logs (different retention periods)
    Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'log_name' => 'default', // 3 years
            'created_at' => Carbon::now()->subYears(4)->startOfYear(),
        ]);

    Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'log_name' => 'invoice_generated', // 8 years
            'created_at' => Carbon::now()->subYears(9)->startOfYear(),
        ]);

    Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'log_name' => 'annual_closing', // 10 years
            'created_at' => Carbon::now()->subYears(11)->startOfYear(),
        ]);

    // Act
    $this->artisan('activity:apply-retention')
        ->expectsOutputToContain('3-year retention')
        ->expectsOutputToContain('8-year retention')
        ->expectsOutputToContain('10-year retention')
        ->expectsOutputToContain('Retention Statistics')
        ->assertSuccessful();

    // Verify: All 3 logs archived and hard deleted, none remain
    expect(Activity::count())->toBe(0);
    expect(ActivityArchive::count())->toBe(3);
});

test('it preserves merkle tree data in archive', function () {
    // Create log with merkle tree data
    $log = Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'log_name' => 'default',
            'created_at' => Carbon::now()->subYears(4)->startOfYear(),
            'merkle_root' => 'merkle_root_hash_123',
            'merkle_batch_id' => 42,
        ]);

    // Act
    $this->artisan('activity:apply-retention')
        ->assertSuccessful();

    // Assert: Merkle data preserved in archive
    $this->assertDatabaseHas('activity_log_archive', [
        'id' => $log->id,
        'merkle_root' => 'merkle_root_hash_123',
        'merkle_batch_id' => 42,
    ]);
});

test('it handles dry run mode', function () {
    // Create expired log
    $log = Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'log_name' => 'default',
            'created_at' => Carbon::now()->subYears(4)->startOfYear(),
        ]);

    // Act: Dry run
    $this->artisan('activity:apply-retention --dry-run')
        ->expectsOutputToContain('Would archive and hard delete')
        ->assertSuccessful();

    // Assert: Nothing actually happened
    $this->assertDatabaseHas('activity_log', ['id' => $log->id]);
    $this->assertDatabaseMissing('activity_log_archive', ['id' => $log->id]);
});

test('it only archives logs that match cutoff date', function () {
    // Create log EXACTLY at cutoff (should NOT be deleted)
    $cutoffDate = Carbon::now()->subYears(3)->endOfYear()->addDay()->startOfDay();
    $logAtCutoff = Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'log_name' => 'default',
            'created_at' => $cutoffDate,
        ]);

    // Create log BEFORE cutoff (should be deleted)
    $logBeforeCutoff = Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'log_name' => 'default',
            'created_at' => $cutoffDate->copy()->subDay(),
        ]);

    // Act
    $this->artisan('activity:apply-retention')
        ->assertSuccessful();

    // Assert: Only log BEFORE cutoff is archived + hard deleted
    $this->assertDatabaseHas('activity_log', ['id' => $logAtCutoff->id]); // Still active
    $this->assertDatabaseMissing('activity_log', ['id' => $logBeforeCutoff->id]); // Hard deleted
    $this->assertDatabaseHas('activity_log_archive', ['id' => $logBeforeCutoff->id]);
});

test('it verifies gdpr compliance no personal data in archive', function () {
    // Create log with extensive personal data
    $log = Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'log_name' => 'default',
            'created_at' => Carbon::now()->subYears(4)->startOfYear(),
            'description' => 'User John Doe logged in from Berlin office',
            'properties' => [
                'email' => 'john.doe@example.com',
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0...',
                'session_id' => 'abc123xyz',
            ],
        ]);

    // Act
    $this->artisan('activity:apply-retention')
        ->assertSuccessful();

    // Assert: Archive contains ONLY cryptographic hashes and metadata
    $archive = ActivityArchive::find($log->id);
    expect($archive)->not->toBeNull();

    // Verify: Only allowed fields exist
    expect($archive->id)->not->toBeNull();
    expect($archive->tenant_id)->not->toBeNull();
    expect($archive->log_name)->not->toBeNull();
    expect($archive->created_at)->not->toBeNull();
    expect($archive->event_hash)->not->toBeNull();
    // previous_hash, merkle_root, merkle_batch_id can be null

    // Verify: NO personal data columns (GDPR Art. 5(1)(e) - data minimization)
    $archiveArray = $archive->toArray();
    expect($archiveArray)->not->toHaveKey('description');
    expect($archiveArray)->not->toHaveKey('properties');
    expect($archiveArray)->not->toHaveKey('subject_id');
    expect($archiveArray)->not->toHaveKey('subject_type');
    expect($archiveArray)->not->toHaveKey('causer_id');
    expect($archiveArray)->not->toHaveKey('causer_type');
    expect($archiveArray)->not->toHaveKey('updated_at');
    expect($archiveArray)->not->toHaveKey('deleted_at');
});

test('it processes logs atomically in transaction', function () {
    // Create 2 expired logs
    $log1 = Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'created_at' => Carbon::now()->subYears(4),
        ]);

    $log2 = Activity::factory()
        ->for($this->tenant, 'tenant')
        ->create([
            'created_at' => Carbon::now()->subYears(4),
        ]);

    // Act: Archive and delete both logs
    $this->artisan('activity:apply-retention')
        ->assertSuccessful();

    // Verify: Both logs archived and hard deleted atomically
    expect(Activity::count())->toBe(0);
    expect(ActivityArchive::count())->toBe(2);

    // Verify: Archives exist for both original log IDs
    expect(ActivityArchive::find($log1->id))->not->toBeNull();
    expect(ActivityArchive::find($log2->id))->not->toBeNull();
});
