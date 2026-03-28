<?php

/**
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Jobs\BuildMerkleTreeBatch;
use App\Jobs\SubmitMerkleRootToOpenTimestamp;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\OpenTimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * TDD Tests for BuildMerkleTreeBatch refactoring.
 *
 * After refactoring to retention-based model:
 * - ALL log types should get Merkle tree (not just Level 2+3)
 * - ALL batches should trigger OTS submission (not just Level 3)
 *
 * Written BEFORE implementation (Test-Driven Development).
 * These tests should FAIL initially.
 *
 * @see https://github.com/SecPal/api/issues/441
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([SubmitMerkleRootToOpenTimestamp::class]);
    $this->mock(OpenTimestampService::class)
        ->shouldReceive('submit')
        ->andReturn('mock-ots-proof');
    $this->tenant = TenantKey::factory()->create();
    $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->actingAs($this->user);
});

/**
 * TDD: ALL log types should get Merkle tree (not just Level 2+3).
 *
 * Expected: FAIL (currently only Level 2+3 processed)
 */
test('all log types get merkle tree', function () {
    // Create logs with different retention periods
    $log3Years = activity('shift_management') // 3 years retention
        ->performedOn($this->customer)
        ->log('Shift assigned');

    $log8Years = activity('invoice_generated') // 8 years retention
        ->performedOn($this->customer)
        ->log('Invoice created');

    $log10Years = activity('annual_closing') // 10 years retention
        ->performedOn($this->customer)
        ->log('Year closed');

    // Initially no merkle_root
    expect($log3Years->fresh()->merkle_root)->toBeNull();
    expect($log8Years->fresh()->merkle_root)->toBeNull();
    expect($log10Years->fresh()->merkle_root)->toBeNull();

    // Run batch job
    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // ALL should have merkle_root now
    $log3Years->refresh();
    $log8Years->refresh();
    $log10Years->refresh();

    expect($log3Years->merkle_root)->not->toBeNull('3-year retention log should have merkle_root');
    expect($log8Years->merkle_root)->not->toBeNull('8-year retention log should have merkle_root');
    expect($log10Years->merkle_root)->not->toBeNull('10-year retention log should have merkle_root');
});

/**
 * TDD: All logs in same batch should have same merkle_root.
 *
 * Expected: FAIL (3-year logs currently not batched)
 */
test('all logs in same tenant get same batch id', function () {
    // Create multiple logs of different types
    $log1 = activity('shift_management')->performedOn($this->customer)->log('Log 1');
    $log2 = activity('invoice_generated')->performedOn($this->customer)->log('Log 2');
    $log3 = activity('security')->performedOn($this->customer)->log('Log 3');

    $job = new BuildMerkleTreeBatch;
    $job->handle();

    $log1->refresh();
    $log2->refresh();
    $log3->refresh();

    // All should be in SAME batch (same tenant, same hour)
    expect($log1->merkle_batch_id)->toBe(
        $log2->merkle_batch_id,
        'All logs should have same batch_id'
    );

    expect($log2->merkle_batch_id)->toBe(
        $log3->merkle_batch_id,
        'All logs should have same batch_id'
    );

    // All should have same merkle_root
    expect($log1->merkle_root)->toBe(
        $log2->merkle_root,
        'All logs should have same merkle_root'
    );
});

/**
 * TDD: OTS should be dispatched for ALL batches (not just Level 3).
 *
 * Expected: FAIL (currently only dispatched if hasLevel3)
 */
test('ots dispatched for all batches', function () {
    Queue::fake();

    // Create ONLY 3-year retention logs (previously "Level 1")
    activity('shift_management')->performedOn($this->customer)->log('Shift 1');
    activity('authentication')->performedOn($this->customer)->log('Login');
    activity('security')->performedOn($this->customer)->log('Access granted');

    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // OTS should be dispatched even for "low retention" logs
    Queue::assertPushed(SubmitMerkleRootToOpenTimestamp::class);
});

/**
 * TDD: OTS dispatched with correct parameters.
 *
 * Expected: FAIL (depends on previous test passing)
 */
test('ots dispatched with correct parameters', function () {
    Queue::fake();

    activity('shift_management')->performedOn($this->customer)->log('Test log');

    $job = new BuildMerkleTreeBatch;
    $job->handle();

    Queue::assertPushed(SubmitMerkleRootToOpenTimestamp::class, function ($job) {
        return $job->tenantId === $this->tenant->id
            && $job->batchId > 0
            && strlen($job->merkleRoot) === 64; // SHA256 hex
    });
});

/**
 * TDD: Job processes logs from all retention periods.
 *
 * Expected: FAIL (currently filters Level 2+3 only)
 */
test('job processes all retention periods', function () {
    // Create mix of retention periods
    $logs = [
        activity('shift_management')->performedOn($this->customer)->log('3y'), // 3 years
        activity('authentication')->performedOn($this->customer)->log('3y'), // 3 years
        activity('invoice_generated')->performedOn($this->customer)->log('8y'), // 8 years
        activity('contract_change')->performedOn($this->customer)->log('8y'), // 8 years
        activity('annual_closing')->performedOn($this->customer)->log('10y'), // 10 years
    ];

    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // ALL should be processed
    foreach ($logs as $log) {
        $log->refresh();
        expect($log->merkle_root)->not->toBeNull(
            "Log '{$log->description}' (retention: ".Activity::getRetentionYearsForLogType($log->log_name).' years) should have merkle_root'
        );
    }
});

/**
 * TDD: Merkle proofs are self-contained (contain sibling hashes).
 *
 * This test verifies refactoring doesn't break merkle proof structure.
 *
 * Expected: PASS (existing functionality)
 */
test('merkle proofs contain sibling hashes', function () {
    activity('shift_management')->performedOn($this->customer)->log('Log 1');
    activity('shift_management')->performedOn($this->customer)->log('Log 2');
    activity('shift_management')->performedOn($this->customer)->log('Log 3');

    $job = new BuildMerkleTreeBatch;
    $job->handle();

    $log = Activity::where('tenant_id', $this->tenant->id)->first();
    $log->refresh();

    expect($log->merkle_proof)->not->toBeNull();
    expect($log->merkle_proof)->toBeArray();

    // Proof should contain sibling hashes
    foreach ($log->merkle_proof as $sibling) {
        expect($sibling)->toHaveKey('hash');
        expect($sibling)->toHaveKey('position');
        expect($sibling['hash'])->toBeString();
        expect($sibling['position'])->toBeIn(['left', 'right']);
    }
});

/**
 * TDD: Batch integrity metadata is preserved.
 *
 * Expected: PASS (existing functionality)
 */
test('batch count stored for integrity checking', function () {
    // Create 5 logs
    for ($i = 1; $i <= 5; $i++) {
        activity('shift_management')->performedOn($this->customer)->log("Log {$i}");
    }

    $job = new BuildMerkleTreeBatch;
    $job->handle();

    $logs = Activity::where('tenant_id', $this->tenant->id)->get();

    // All logs should have same batch count (test creates 6 logs above)
    $expectedCount = $logs->count();
    foreach ($logs as $log) {
        expect($log->merkle_batch_count)->toBe($expectedCount);
    }
});

/**
 * TDD: Multiple tenants are isolated.
 *
 * Expected: PASS (existing functionality)
 */
test('multiple tenants get separate batches', function () {
    $tenant2 = TenantKey::factory()->create();
    $customer2 = Customer::factory()->create(['tenant_id' => $tenant2->id]);
    $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

    // Tenant 1 logs
    activity('shift_management')->performedOn($this->customer)->log('Tenant 1 Log');

    // Tenant 2 logs
    $this->actingAs($user2);
    activity('shift_management')->performedOn($customer2)->log('Tenant 2 Log');

    $job = new BuildMerkleTreeBatch;
    $job->handle();

    $log1 = Activity::where('tenant_id', $this->tenant->id)->first();
    $log2 = Activity::where('tenant_id', $tenant2->id)->first();

    // Different batch IDs
    expect($log1->merkle_batch_id)->not->toBe($log2->merkle_batch_id);

    // Different merkle roots
    expect($log1->merkle_root)->not->toBe($log2->merkle_root);
});

/**
 * TDD: Job only processes unbatched logs.
 *
 * Expected: PASS (existing functionality)
 */
test('job skips already batched logs', function () {
    $log1 = activity('shift_management')->performedOn($this->customer)->log('Log 1');

    // First run
    $job = new BuildMerkleTreeBatch;
    $job->handle();

    $log1->refresh();
    $originalBatchId = $log1->merkle_batch_id;
    $originalRoot = $log1->merkle_root;

    // Create new log
    activity('shift_management')->performedOn($this->customer)->log('Log 2');

    // Second run
    $job2 = new BuildMerkleTreeBatch;
    $job2->handle();

    $log1->refresh();

    // Original log should keep same batch data
    expect($log1->merkle_batch_id)->toBe($originalBatchId);
    expect($log1->merkle_root)->toBe($originalRoot);
});

/**
 * TDD: Empty tenants are skipped gracefully.
 *
 * Expected: PASS (existing functionality)
 */
test('job handles empty tenants gracefully', function () {
    // Don't create any logs

    $job = new BuildMerkleTreeBatch;
    $job->handle(); // Should not throw

    expect(true)->toBeTrue('Job should handle empty tenants without errors');
});
