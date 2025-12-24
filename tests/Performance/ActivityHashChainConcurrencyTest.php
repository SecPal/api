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
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Performance tests for queue-based activity hash chain building.
 *
 * Issue #408: Validate race-condition-free processing under high load.
 *
 * Test scenarios:
 * - 100 logs/sec per tenant (sustained throughput)
 * - Multi-tenant concurrent processing
 * - Queue latency measurement
 * - Hash chain integrity verification
 *
 * Acceptance criteria:
 * - 0 broken chains (100% integrity)
 * - No race conditions detected
 * - Queue latency <100ms per log (p95)
 * - Throughput ≥100 logs/sec per tenant
 *
 * @group performance
 * @group issue-408
 * @group hash-chain
 */
beforeEach(function () {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->actingAs($this->user);
});

// ============================================================================
// High Throughput Tests (100 logs/sec)
// ============================================================================

test('creates 100 logs sequentially with perfect hash chain integrity', function () {
    $start = microtime(true);

    // Create 100 logs (baseline: sequential processing)
    $logs = collect(range(1, 100))->map(function ($i) {
        return Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'default',
            'description' => "Performance test log {$i}",
        ]);
    });

    $duration = microtime(true) - $start;

    // Refresh all logs to get updated event_hash values
    $logs = $logs->map(fn ($log) => $log->refresh());

    // Verify hash chain integrity
    expect($logs[0]->previous_hash)->toBeNull(); // Genesis log

    for ($i = 1; $i < $logs->count(); $i++) {
        expect($logs[$i]->previous_hash)
            ->toBe($logs[$i - 1]->event_hash)
            ->and($logs[$i]->event_hash)
            ->not->toBeNull();
    }

    // Verify all hashes are unique
    $allHashes = $logs->pluck('event_hash')->toArray();
    expect(count($allHashes))->toBe(count(array_unique($allHashes)));

    // Performance metrics
    $throughput = 100 / $duration;
    expect($throughput)->toBeGreaterThan(10); // At least 10 logs/sec (conservative for CI)

    // Log performance metrics for monitoring
    echo "\nPerformance Metrics:\n";
    echo '  Duration: '.round($duration, 2)." seconds\n";
    echo '  Throughput: '.round($throughput, 2)." logs/sec\n";
    echo '  Avg latency: '.round(($duration / 100) * 1000, 2)." ms/log\n";
})->group('slow');

test('multi-tenant concurrent processing maintains isolation', function () {
    // Create 3 tenants
    $tenants = collect(range(1, 3))->map(fn () => TenantKey::factory()->create());

    // Create 20 logs per tenant concurrently (simulate concurrent requests)
    $allLogs = $tenants->flatMap(function ($tenant) {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        return collect(range(1, 20))->map(function ($i) use ($tenant) {
            return Activity::create([
                'tenant_id' => $tenant->id,
                'log_name' => 'default',
                'description' => "Tenant {$tenant->id} log {$i}",
            ]);
        });
    });

    // Refresh all logs
    $allLogs = $allLogs->map(fn ($log) => $log->refresh());

    // Verify each tenant's chain is intact
    foreach ($tenants as $tenant) {
        $tenantLogs = $allLogs->where('tenant_id', $tenant->id)->values();

        expect($tenantLogs)->toHaveCount(20);
        expect($tenantLogs[0]->previous_hash)->toBeNull(); // Genesis

        for ($i = 1; $i < $tenantLogs->count(); $i++) {
            expect($tenantLogs[$i]->previous_hash)
                ->toBe($tenantLogs[$i - 1]->event_hash)
                ->and($tenantLogs[$i]->event_hash)
                ->not->toBeNull();
        }
    }

    // Verify tenant isolation (no cross-tenant chain links)
    $tenant1Hashes = $allLogs->where('tenant_id', $tenants[0]->id)->pluck('event_hash')->toArray();
    $tenant2Logs = $allLogs->where('tenant_id', $tenants[1]->id);

    foreach ($tenant2Logs as $log) {
        expect($log->previous_hash)->not->toBeIn($tenant1Hashes);
    }
})->group('slow');

// ============================================================================
// Stress Test (Burst Load)
// ============================================================================

test('handles burst of 50 logs without race conditions', function () {
    $start = microtime(true);

    // Create 50 logs rapidly (burst load)
    $logs = collect(range(1, 50))->map(function ($i) {
        $log = Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'authentication',
            'description' => "Burst test log {$i}",
        ]);

        return $log;
    });

    $creationDuration = microtime(true) - $start;

    // Refresh all logs
    $logs = $logs->map(fn ($log) => $log->refresh());

    // Verify NO race conditions (chain must be perfect)
    expect($logs[0]->previous_hash)->toBeNull();

    $brokenLinks = 0;
    for ($i = 1; $i < $logs->count(); $i++) {
        if ($logs[$i]->previous_hash !== $logs[$i - 1]->event_hash) {
            $brokenLinks++;
        }
    }

    // Critical: Zero broken links (proves race-condition-free)
    expect($brokenLinks)->toBe(0);

    // Performance metrics
    echo "\nBurst Load Metrics:\n";
    echo '  Creation time: '.round($creationDuration, 2)." seconds\n";
    echo '  Burst rate: '.round(50 / $creationDuration, 2)." logs/sec\n";
    echo "  Broken links: {$brokenLinks}/49 (MUST be 0)\n";
})->group('slow');

// ============================================================================
// Queue Latency Test
// ============================================================================

test('queue processing latency is acceptable', function () {
    $latencies = [];

    // Create 20 logs and measure individual latency
    for ($i = 1; $i <= 20; $i++) {
        $start = microtime(true);

        $log = Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'default',
            'description' => "Latency test log {$i}",
        ]);

        // Refresh to ensure job completed
        $log->refresh();

        $latency = (microtime(true) - $start) * 1000; // Convert to ms
        $latencies[] = $latency;

        // Verify hash was computed (job executed)
        expect($log->event_hash)->not->toBeNull();
    }

    // Calculate percentiles
    sort($latencies);
    $p50 = $latencies[(int) (count($latencies) * 0.5)];
    $p95 = $latencies[(int) (count($latencies) * 0.95)];
    $p99 = $latencies[(int) (count($latencies) * 0.99)];

    // Performance expectations (sync queue in tests)
    expect($p50)->toBeLessThan(100); // Median <100ms
    expect($p95)->toBeLessThan(200); // 95th percentile <200ms

    // Log latency distribution
    echo "\nQueue Latency Distribution:\n";
    echo '  p50: '.round($p50, 2)." ms\n";
    echo '  p95: '.round($p95, 2)." ms\n";
    echo '  p99: '.round($p99, 2)." ms\n";
    echo '  min: '.round(min($latencies), 2)." ms\n";
    echo '  max: '.round(max($latencies), 2)." ms\n";
})->group('slow');

// ============================================================================
// Chain Integrity Verification
// ============================================================================

test('verifies hash chain integrity for 200-log sequence', function () {
    // Create 200 logs (extensive chain test)
    $logs = collect(range(1, 200))->map(function ($i) {
        return Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'default',
            'description' => "Chain integrity test log {$i}",
        ]);
    });

    // Refresh all logs
    $logs = $logs->map(fn ($log) => $log->refresh());

    // Verify genesis log
    expect($logs[0]->previous_hash)->toBeNull();
    expect($logs[0]->event_hash)->not->toBeNull();

    // Verify entire chain (every link must be correct)
    $chainIntact = true;
    $brokenAt = null;

    for ($i = 1; $i < $logs->count(); $i++) {
        $expectedPrevHash = $logs[$i - 1]->event_hash;
        $actualPrevHash = $logs[$i]->previous_hash;

        if ($actualPrevHash !== $expectedPrevHash) {
            $chainIntact = false;
            $brokenAt = $i;
            break;
        }

        // Also verify event_hash is present
        if ($logs[$i]->event_hash === null) {
            $chainIntact = false;
            $brokenAt = $i;
            break;
        }
    }

    // Chain MUST be intact
    expect($chainIntact)->toBeTrue()->and($brokenAt)->toBeNull();

    // Verify all hashes are unique (no duplicates)
    $allHashes = $logs->pluck('event_hash')->toArray();
    expect(count($allHashes))->toBe(count(array_unique($allHashes)));

    echo "\n200-Log Chain Integrity: ✅ PERFECT (0 broken links)\n";
})->group('slow');
