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
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property User $user
 */
beforeEach(function () {
    Queue::fake([App\Jobs\SubmitMerkleRootToOpenTimestamp::class]);
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->actingAs($this->user);
});

// ============================================================================
// Merkle Tree Building Tests (4 leaves - perfect binary tree)
// ============================================================================

test('merkle tree builds correctly with 4 logs', function () {
    // Create 4 logs
    $logs = collect(range(1, 4))->map(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication', // 3 years retention
        'description' => "Test log {$i}",
    ]));

    // Execute job
    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // Verify all logs have Merkle data
    $logs->each(function ($log) {
        $log->refresh();
        expect($log->merkle_root)->not()->toBeNull();
        expect($log->merkle_batch_id)->not()->toBeNull();
        expect($log->merkle_proof)->toBeArray();
        expect($log->merkle_proof)->not()->toBeEmpty();
    });

    // Verify all logs have same batch_id and root
    $batchIds = $logs->pluck('merkle_batch_id')->unique();
    expect($batchIds)->toHaveCount(1);

    $roots = $logs->pluck('merkle_root')->unique();
    expect($roots)->toHaveCount(1);
});

test('merkle tree handles odd number of leaves correctly', function () {
    // Create 3 logs (odd number)
    $logs = collect(range(1, 3))->map(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'rbac_changes', // 3 years retention
        'description' => "Test log {$i}",
    ]));

    // Execute job
    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // Verify all logs have Merkle data
    $logs->each(function ($log) {
        $log->refresh();
        expect($log->merkle_root)->not()->toBeNull();
        expect($log->merkle_batch_id)->not()->toBeNull();
        expect($log->merkle_proof)->toBeArray();
    });

    // Verify Merkle root is deterministic (duplicate last leaf)
    expect($logs->pluck('merkle_root')->unique())->toHaveCount(1);

    // Verify Merkle proofs are valid (critical for odd-leaf case)
    $logs->each(function ($log) {
        $log->refresh();
        expect($log->verifyMerkleProof())->toBeTrue();
    });
});

test('merkle proof verifies correctly after batching', function () {
    // Create 4 logs
    $logs = collect(range(1, 4))->map(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security', // 3 years retention
        'description' => "Test log {$i}",
    ]));

    // Build Merkle tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // Verify each log's proof
    $logs->each(function ($log) {
        $log->refresh();
        expect($log->verifyMerkleProof())->toBeTrue();
    });
});

test('merkle root is deterministic for same input', function () {
    // Create 4 logs
    $logs = collect(range(1, 4))->map(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => "Test log {$i}",
    ]));

    // Build tree first time
    $job1 = new BuildMerkleTreeBatch;
    $job1->handle();

    $firstRoot = $logs->first()->fresh()->merkle_root;

    // Clear Merkle data
    Activity::whereIn('id', $logs->pluck('id'))
        ->update([
            'merkle_root' => null,
            'merkle_batch_id' => null,
            'merkle_proof' => null,
        ]);

    // Build tree second time
    $job2 = new BuildMerkleTreeBatch;
    $job2->handle();

    $secondRoot = $logs->first()->fresh()->merkle_root;

    // Same input → same root
    expect($firstRoot)->toBe($secondRoot);
});

// ============================================================================
// Level 1 Logs (Should NOT Get Merkle Tree)
// ============================================================================
// NOTE: After retention refactoring (Issue #441), ALL log types get merkle trees.
// The old 'level 1 logs do not get merkle tree' test is obsolete.
// ============================================================================
// Multi-Tenant Isolation
// ============================================================================

test('merkle tree respects tenant isolation', function () {
    $tenant2 = TenantKey::factory()->create();

    // Create logs for tenant 1
    $logs1 = collect(range(1, 2))->map(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => "Tenant 1 log {$i}",
    ]));

    // Build tree for tenant 1
    $job1 = new BuildMerkleTreeBatch;
    $job1->handle();

    // Wait 1 second to ensure different timestamp for tenant 2 batch
    sleep(1);

    // Create logs for tenant 2
    $logs2 = collect(range(1, 2))->map(fn ($i) => Activity::create([
        'tenant_id' => $tenant2->id,
        'log_name' => 'authentication',
        'description' => "Tenant 2 log {$i}",
    ]));

    // Build tree for tenant 2
    $job2 = new BuildMerkleTreeBatch;
    $job2->handle();

    // Verify different batch IDs (timestamp-based)
    $batch1 = $logs1->first()->fresh()->merkle_batch_id;
    $batch2 = $logs2->first()->fresh()->merkle_batch_id;

    expect($batch1)->not()->toBe($batch2);

    // Verify different roots
    $root1 = $logs1->first()->fresh()->merkle_root;
    $root2 = $logs2->first()->fresh()->merkle_root;

    expect($root1)->not()->toBe($root2);
});

// ============================================================================
// Already Batched Logs (Skip)
// ============================================================================

test('job skips already batched logs', function () {
    // Create and batch logs
    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'Test log',
    ]);

    $job1 = new BuildMerkleTreeBatch;
    $job1->handle();

    $originalRoot = $log->fresh()->merkle_root;
    $originalBatch = $log->fresh()->merkle_batch_id;

    // Run job again
    $job2 = new BuildMerkleTreeBatch;
    $job2->handle();

    // Verify unchanged
    $log->refresh();
    expect($log->merkle_root)->toBe($originalRoot);
    expect($log->merkle_batch_id)->toBe($originalBatch);
});

// ============================================================================
// Empty Tenant (No Logs to Batch)
// ============================================================================

test('job handles tenant with no unbatched logs', function () {
    // No logs for this tenant
    $job = new BuildMerkleTreeBatch;
    $result = $job->handle();

    // Should complete without error
    expect($result)->toBeNull();
});

// ============================================================================
// ALL Logs Trigger OpenTimestamp Dispatch (Checked via Queue)
// After retention refactoring (Issue #441): ALL logs get OTS, not just "Level 3"
// ============================================================================

test('all logs schedule opentimestamp submission', function () {
    // Create log (ProcessActivityHashChain runs via dispatchSync)
    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication', // 3 years retention
        'description' => 'User login',
    ]);
    $log->refresh();

    // NOW fake queue (after hash chain is built)
    Queue::fake();

    // Build tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // Verify OTS job dispatched
    Queue::assertPushed(App\Jobs\SubmitMerkleRootToOpenTimestamp::class, function ($job) use ($log) {
        $log->refresh();

        return $job->tenantId === $log->tenant_id
            && $job->batchId === $log->merkle_batch_id
            && $job->merkleRoot === $log->merkle_root;
    });
});

test('multiple log types all get opentimestamp', function () {
    // Create logs with DIFFERENT retention periods (3, 8, 10 years)
    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication', // 3 years
        'description' => 'Login',
    ]);
    $log2 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'invoice_generated', // 8 years
        'description' => 'Invoice created',
    ]);
    $log3 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'annual_closing', // 10 years
        'description' => 'Annual report',
    ]);
    $log1->refresh();
    $log2->refresh();
    $log3->refresh();

    // NOW fake queue (after hash chain is built)
    Queue::fake();

    // Build tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // Verify OTS job dispatched for ALL logs (not filtered by level anymore)
    Queue::assertPushed(App\Jobs\SubmitMerkleRootToOpenTimestamp::class);
});
