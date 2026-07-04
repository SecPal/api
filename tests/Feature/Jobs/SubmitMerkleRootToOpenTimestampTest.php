<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

use App\Jobs\SubmitMerkleRootToOpenTimestamp;
use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\OpenTimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * Test SubmitMerkleRootToOpenTimestamp job.
 *
 * Tests submission of Merkle roots to OpenTimestamp calendar servers
 * and storage of pending proofs.
 *
 * @see SubmitMerkleRootToOpenTimestamp
 * @see Issue #391 PR-6: Integrate OpenTimestamp PHP library
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->for($this->tenant, 'tenant')->create();
});

test('job submits merkle root and stores proof', function () {
    // Arrange: Create logs with Merkle root
    $batchId = now()->timestamp;
    $merkleRoot = hash('sha256', 'test-merkle-root');

    $logs = collect(range(1, 5))->map(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication', // 3 years retention
        'description' => "Test activity log {$i}",
        'merkle_batch_id' => $batchId,
        'merkle_root' => $merkleRoot,
        'ots_proof' => null,
        'ots_submitted_at' => null,
    ]));

    // Mock OpenTimestamp service
    /** @var OpenTimestampService&Mockery\MockInterface $mockService */
    $mockService = $this->mock(OpenTimestampService::class);
    $mockProof = hex2bin('0004f0'.bin2hex('pending-proof'));

    $mockService->shouldReceive('submit')
        ->once()
        ->with($merkleRoot)
        ->andReturn($mockProof);

    // Act: Dispatch job
    $job = new SubmitMerkleRootToOpenTimestamp(
        $this->tenant->id,
        $batchId,
        $merkleRoot
    );
    $job->handle($mockService);

    // Assert: All logs in batch updated with OTS proof
    $logs = Activity::where('tenant_id', $this->tenant->id)
        ->where('merkle_batch_id', $batchId)
        ->get();

    expect($logs)->toHaveCount(5, 'Should find 5 logs after job execution');

    foreach ($logs as $log) {
        expect($log->ots_proof)->not->toBeNull("Log {$log->id} should have ots_proof");
        expect($log->ots_proof)->toBe($mockProof);
        expect($log->ots_submitted_at)->not->toBeNull();
        expect($log->ots_confirmed_at)->toBeNull(); // Not yet confirmed
    }
});

test('job only updates logs in specified batch', function () {
    // Arrange: Create logs in multiple batches
    $batchId1 = 1000;
    $batchId2 = 2000;
    $merkleRoot1 = hash('sha256', 'root-1');
    $merkleRoot2 = hash('sha256', 'root-2');

    collect(range(1, 3))->each(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'hr_access',
        'description' => "Batch 1 log {$i}",
        'merkle_batch_id' => $batchId1,
        'merkle_root' => $merkleRoot1,
    ]));

    collect(range(1, 2))->each(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'hr_access',
        'description' => "Batch 2 log {$i}",
        'merkle_batch_id' => $batchId2,
        'merkle_root' => $merkleRoot2,
    ]));

    // Mock service
    /** @var OpenTimestampService&Mockery\MockInterface $mockService */
    $mockService = $this->mock(OpenTimestampService::class);
    $mockService->shouldReceive('submit')
        ->with($merkleRoot1)
        ->andReturn('proof1');

    // Act: Submit only batch 1
    $job = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, $batchId1, $merkleRoot1);
    $job->handle($mockService);

    // Assert: Only batch 1 logs updated
    expect(Activity::whereNotNull('ots_submitted_at')->count())->toBe(3);
    expect(Activity::whereNull('ots_submitted_at')->count())->toBe(2);
});

test('job handles submission failure gracefully', function () {
    // Arrange: Create logs
    $batchId = now()->timestamp;
    $merkleRoot = hash('sha256', 'test-root');

    Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'hr_access',
        'description' => 'Test failure handling',
        'merkle_batch_id' => $batchId,
        'merkle_root' => $merkleRoot,
    ]);

    // Mock service - submission fails
    /** @var OpenTimestampService&Mockery\MockInterface $mockService */
    $mockService = $this->mock(OpenTimestampService::class);
    $mockService->shouldReceive('submit')
        ->andThrow(new RuntimeException('Calendar servers unavailable'));

    // Act & Assert: Job should throw exception (will be retried by queue)
    expect(fn () => (new SubmitMerkleRootToOpenTimestamp($this->tenant->id, $batchId, $merkleRoot))->handle($mockService))
        ->toThrow(RuntimeException::class);

    // Logs should NOT be updated
    $log = Activity::first();
    assert($log instanceof Activity);
    expect($log->ots_proof)->toBeNull();
    expect($log->ots_submitted_at)->toBeNull();
});

test('job is queued on opentimestamp queue', function () {
    Queue::fake();

    // Act: Dispatch job
    SubmitMerkleRootToOpenTimestamp::dispatch(1, 1000, 'abc123');

    // Assert: Job queued on correct queue
    Queue::assertPushedOn('opentimestamp', SubmitMerkleRootToOpenTimestamp::class);
});

test('job skips if no logs found', function () {
    // Arrange: No logs with specified batch
    $batchId = 9999;
    $merkleRoot = hash('sha256', 'root');

    /** @var OpenTimestampService&Mockery\MockInterface $mockService */
    $mockService = $this->mock(OpenTimestampService::class);
    $mockService->shouldNotReceive('submit');

    // Act: Job should handle gracefully
    $job = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, $batchId, $merkleRoot);
    $job->handle($mockService);

    // Assert: No errors, no submissions
    expect(Activity::whereNotNull('ots_submitted_at')->count())->toBe(0);
});

test('job converts binary proof for storage', function () {
    // Arrange: Create log
    $batchId = now()->timestamp;
    $merkleRoot = hash('sha256', 'test');

    Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'contract_change',
        'description' => 'Test binary proof storage',
        'merkle_batch_id' => $batchId,
        'merkle_root' => $merkleRoot,
    ]);

    // Mock service - returns binary proof
    $binaryProof = "\x00\x04\xf0".random_bytes(50);

    /** @var OpenTimestampService&Mockery\MockInterface $mockService */
    $mockService = $this->mock(OpenTimestampService::class);
    $mockService->shouldReceive('submit')
        ->andReturn($binaryProof);

    // Act
    $job = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, $batchId, $merkleRoot);
    $job->handle($mockService);

    // Assert: Binary proof stored correctly
    $log = Activity::first();
    assert($log instanceof Activity);
    expect($log->ots_proof)->not->toBeNull();
    expect($log->ots_proof)->toBe($binaryProof);
});

test('job uses exponential backoff on retry', function () {
    // Arrange: Create log with Merkle root
    $batchId = now()->timestamp;
    $merkleRoot = hash('sha256', 'test-backoff');

    Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'contract_change',
        'description' => 'Test exponential backoff',
        'merkle_batch_id' => $batchId,
        'merkle_root' => $merkleRoot,
    ]);

    // Create job instance
    $job = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, $batchId, $merkleRoot);

    // Assert: Job has exponential backoff configured
    // backoff() method should return [1, 2, 4] for exponential delays
    expect($job->backoff())->toBe([1, 2, 4]);
    expect($job->tries)->toBe(3);
    expect($job->timeout)->toBe(30);
});
