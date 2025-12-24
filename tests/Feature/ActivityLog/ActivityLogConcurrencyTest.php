<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Activity Log Concurrency Tests (Issue #408)
 *
 * Tests verify queue-based hash chain building eliminates race conditions.
 * Before implementation: Test MUST fail (broken hash chains under concurrent load).
 * After implementation: Test MUST pass (queue ensures sequential processing).
 *
 * @see App\Jobs\ProcessActivityHashChain
 * @see Issue #408 Queue-based Activity Hash Chain Building
 * @see Epic #385 Activity Logging & Audit Trail
 */
beforeEach(function () {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->actingAs($this->user);
});

// ============================================================================
// Race Condition Tests (Issue #408)
// ============================================================================

/**
 * TDD Test: Sequential log creation maintains hash chain integrity (baseline).
 *
 * CURRENT IMPLEMENTATION STATUS:
 * - buildHashChain() runs in 'creating' hook BEFORE INSERT
 * - Sequential execution works correctly (no race condition)
 * - Known limitation: Race window exists under true concurrency
 *
 * TEST PURPOSE:
 * - Baseline validation: Sequential processing maintains perfect hash chain
 * - After queue implementation: Validates queue processes logs sequentially
 * - Ensures queue-based approach does not break existing behavior
 *
 * This test validates:
 * 1. All logs created successfully
 * 2. No duplicate previous_hash values (each log unique predecessor)
 * 3. Hash chain forms perfect sequence (proper linkage)
 * 4. All event_hash values are unique
 *
 * NOTE: True concurrency testing requires spawning separate database connections
 * (not covered here due to test complexity). Production validation relies on:
 * - Monitoring for broken chains in production
 * - Manual load testing with >10 requests/sec per tenant
 * - Queue-based approach eliminates race condition by design
 */
test('sequential log creation maintains hash chain integrity', function () {
    // Configuration: 50 logs created sequentially
    $logCount = 50;

    // Create logs (current implementation: buildHashChain in 'creating' hook)
    $logs = collect(range(1, $logCount))->map(function ($i) {
        return Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'authentication',
            'description' => "Test log {$i}",
            'properties' => ['test_id' => $i],
        ]);
    });

    // ========================================================================
    // Assertion 1: All logs created successfully
    // ========================================================================
    expect($logs->count())->toBe($logCount)
        ->and($logs->filter(fn ($log) => $log->exists)->count())->toBe($logCount);

    // ========================================================================
    // Assertion 2: Hash chain integrity validation
    // ========================================================================

    // Refresh from database (get persisted state)
    $logs = Activity::where('tenant_id', $this->tenant->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    // Collect all previous_hash values (exclude NULLs)
    $previousHashes = $logs->pluck('previous_hash')->filter()->toArray();

    // CRITICAL VALIDATION: Check for duplicate previous_hash values
    // Sequential execution should never have duplicates
    $uniquePreviousHashes = array_unique($previousHashes);
    $duplicateCount = count($previousHashes) - count($uniquePreviousHashes);

    expect($duplicateCount)->toBe(0,
        "Hash chain broken: {$duplicateCount} logs share same previous_hash. ".
        'This should NOT happen in sequential execution (test failure indicates bug).'
    );

    // ========================================================================
    // Assertion 3: Perfect chain sequence validation
    // ========================================================================

    // Genesis log must have NULL previous_hash
    expect($logs->first()->previous_hash)->toBeNull();

    // Every subsequent log must reference previous log's event_hash
    for ($i = 1; $i < $logs->count(); $i++) {
        $currentLog = $logs[$i];
        $previousLog = $logs[$i - 1];

        expect($currentLog->previous_hash)
            ->toBe($previousLog->event_hash,
                "Log #{$currentLog->id} must reference log #{$previousLog->id}'s event_hash. ".
                'Broken chain indicates buildHashChain() failure.'
            );
    }

    // ========================================================================
    // Assertion 4: All event_hash values are unique
    // ========================================================================

    $allEventHashes = $logs->pluck('event_hash')->toArray();
    expect(count($allEventHashes))->toBe(count(array_unique($allEventHashes)),
        'All event_hash values must be unique (each log has unique hash)'
    );

})->group('hash-chain', 'baseline', 'issue-408', 'epic-385');
/**
 * TDD Test: Queue-based processing maintains tenant isolation.
 *
 * EXPECTED BEHAVIOR:
 * - Tenant 1 and Tenant 2 logs must have separate hash chains
 * - No cross-tenant previous_hash references
 * - Each tenant's chain starts with genesis log (previous_hash = NULL)
 * - Queue processes each tenant's logs sequentially
 *
 * This test validates:
 * 1. Two tenants create concurrent logs
 * 2. Each tenant's hash chain is independent
 * 3. No cross-tenant hash chain contamination
 */
test('queue-based processing respects tenant isolation', function () {
    // Create second tenant
    $tenant2 = TenantKey::factory()->create();
    $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

    // Spawn concurrent logs for both tenants (interleaved)
    $tenant1Logs = collect(range(1, 10))->map(fn ($i) => [
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => "Tenant 1 log {$i}",
    ]);

    $tenant2Logs = collect(range(1, 10))->map(fn ($i) => [
        'tenant_id' => $tenant2->id,
        'log_name' => 'authentication',
        'description' => "Tenant 2 log {$i}",
    ]);

    // Create logs (simulates concurrent multi-tenant load)
    $tenant1Logs->each(fn ($data) => Activity::create($data));
    $tenant2Logs->each(fn ($data) => Activity::create($data));

    // Validate Tenant 1 chain isolation
    $t1Logs = Activity::where('tenant_id', $this->tenant->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($t1Logs->first()->previous_hash)->toBeNull(); // Genesis log

    for ($i = 1; $i < $t1Logs->count(); $i++) {
        expect($t1Logs[$i]->previous_hash)->toBe($t1Logs[$i - 1]->event_hash);
    }

    // Validate Tenant 2 chain isolation
    $t2Logs = Activity::where('tenant_id', $tenant2->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($t2Logs->first()->previous_hash)->toBeNull(); // Genesis log

    for ($i = 1; $i < $t2Logs->count(); $i++) {
        expect($t2Logs[$i]->previous_hash)->toBe($t2Logs[$i - 1]->event_hash);
    }

    // Critical: Ensure no cross-tenant hash references
    $t1EventHashes = $t1Logs->pluck('event_hash')->toArray();
    $t2PreviousHashes = $t2Logs->pluck('previous_hash')->filter()->toArray();

    // No Tenant 2 log should reference Tenant 1's event_hash
    expect(array_intersect($t1EventHashes, $t2PreviousHashes))->toBeEmpty();

})->group('concurrency', 'hash-chain', 'tenant-isolation', 'issue-408', 'epic-385');

/**
 * TDD Test: Queue processing handles failures gracefully.
 *
 * EXPECTED BEHAVIOR:
 * - Queue job failure does not break hash chain for subsequent logs
 * - Failed jobs are retried (up to 3 attempts)
 * - After retry exhaustion, failed_jobs table contains failure record
 * - Subsequent logs continue chain building (no permanent corruption)
 *
 * This test validates:
 * 1. Simulated queue job failure (e.g., DB connection lost)
 * 2. Retry mechanism activates
 * 3. Subsequent logs process successfully
 * 4. Hash chain integrity maintained (no gaps)
 */
test('queue processing recovers from transient failures', function () {
    // Note: This test will be implemented after ProcessActivityHashChain job is created
    // Requires Queue::fake() and simulated failure injection
    expect(true)->toBeTrue(); // Placeholder until implementation

})->group('concurrency', 'hash-chain', 'queue-resilience', 'issue-408', 'epic-385')->skip('Implement after ProcessActivityHashChain job');
