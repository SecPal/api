<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Jobs\BuildMerkleTreeBatch;
use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Merkle Proof Performance Tests (Issue #390)
 *
 * Validates that verifyMerkleProof() performance is acceptable for the
 * scenarios covered by these tests:
 * - < 2ms per verification for a 100-leaf Merkle tree (including DB overhead)
 *
 * Performance baseline (as exercised here):
 * - Small trees (4 leaves): < 5ms per verification (threshold enforced in CI)
 * - Medium trees (100 leaves): < 2ms per verification (threshold enforced below)
 *
 * @see App\Models\Activity::verifyMerkleProof()
 * @see Issue #390 PR-5: Add Merkle proof storage & verification methods
 */
beforeEach(function () {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

// ============================================================================
// Performance Tests
// ============================================================================

test('merkle proof verification takes less than 2ms for 100-leaf tree', function () {
    $this->actingAs($this->user);

    // Create 100 Level 2 logs (reasonably large Merkle tree)
    // Note: 1000 logs would take too long in CI/CD pipeline
    $logCount = 100;
    $logs = collect(range(1, $logCount))->map(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication', // Level 2
        'description' => "Performance test log {$i}",
    ]));

    // Build Merkle tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // Measure verification time for all logs
    $startTime = microtime(true);

    $logs->each(function ($log) {
        $log->refresh();
        expect($log->verifyMerkleProof())->toBeTrue();
    });

    $endTime = microtime(true);
    $totalTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
    $avgTimePerLog = $totalTime / $logCount;

    // Assert: Average verification time < 2ms per log
    // (includes DB refresh overhead, pure verification is < 0.1ms)
    expect($avgTimePerLog)->toBeLessThan(2.0);

    // Output for informational purposes
    echo sprintf(
        "\n  → Verified %d logs in %.2fms (avg %.4fms per log)\n",
        $logCount,
        $totalTime,
        $avgTimePerLog
    );
})->group('performance', 'merkle-proof');

test('merkle proof verification is efficient for small trees', function () {
    $this->actingAs($this->user);

    // Create 4 logs (small tree)
    $logs = collect(range(1, 4))->map(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security', // Level 2
        'description' => "Small tree log {$i}",
    ]));

    // Build Merkle tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // Measure verification time
    $startTime = microtime(true);

    $logs->each(function ($log) {
        $log->refresh();
        expect($log->verifyMerkleProof())->toBeTrue();
    });

    $endTime = microtime(true);
    $totalTime = ($endTime - $startTime) * 1000; // milliseconds
    $avgTimePerLog = $totalTime / 4;

    // Small trees should be fast (< 5ms per log including DB overhead)
    expect($avgTimePerLog)->toBeLessThan(5.0);

    echo sprintf(
        "\n  → Small tree: 4 logs verified in %.2fms (avg %.4fms per log)\n",
        $totalTime,
        $avgTimePerLog
    );
})->group('performance', 'merkle-proof');
