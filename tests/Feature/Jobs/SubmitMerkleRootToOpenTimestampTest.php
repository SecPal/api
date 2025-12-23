<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SubmitMerkleRootToOpenTimestamp;
use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\OpenTimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Test SubmitMerkleRootToOpenTimestamp job.
 *
 * Tests submission of Merkle roots to OpenTimestamp calendar servers
 * and storage of pending proofs.
 *
 * @see App\Jobs\SubmitMerkleRootToOpenTimestamp
 * @see Issue #391 PR-6: Integrate OpenTimestamp PHP library
 */
class SubmitMerkleRootToOpenTimestampTest extends TestCase
{
    use RefreshDatabase;

    private TenantKey $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantKey::factory()->create();
        $this->user = User::factory()->for($this->tenant, 'tenant')->create();
    }

    public function test_job_submits_merkle_root_and_stores_proof(): void
    {
        // Arrange: Create Level 3 logs with Merkle root
        $batchId = now()->timestamp;
        $merkleRoot = hash('sha256', 'test-merkle-root');

        $logs = collect(range(1, 5))->map(fn ($i) => Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'hr_access', // Level 3
            'description' => "Test HR access log {$i}",
            'merkle_batch_id' => $batchId,
            'merkle_root' => $merkleRoot,
            'ots_proof' => null,
            'ots_submitted_at' => null,
        ]));

        // Mock OpenTimestamp service
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

        $this->assertCount(5, $logs, 'Should find 5 logs after job execution');

        foreach ($logs as $log) {
            $this->assertNotNull($log->ots_proof, "Log {$log->id} should have ots_proof");
            $this->assertEquals($mockProof, $log->ots_proof);
            $this->assertNotNull($log->ots_submitted_at);
            $this->assertNull($log->ots_confirmed_at); // Not yet confirmed
        }
    }

    public function test_job_only_updates_logs_in_specified_batch(): void
    {
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
        $mockService = $this->mock(OpenTimestampService::class);
        $mockService->shouldReceive('submit')
            ->with($merkleRoot1)
            ->andReturn('proof1');

        // Act: Submit only batch 1
        $job = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, $batchId1, $merkleRoot1);
        $job->handle($mockService);

        // Assert: Only batch 1 logs updated
        $this->assertEquals(3, Activity::whereNotNull('ots_submitted_at')->count());
        $this->assertEquals(2, Activity::whereNull('ots_submitted_at')->count());
    }

    public function test_job_handles_submission_failure_gracefully(): void
    {
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
        $mockService = $this->mock(OpenTimestampService::class);
        $mockService->shouldReceive('submit')
            ->andThrow(new \RuntimeException('Calendar servers unavailable'));

        // Act & Assert: Job should throw exception (will be retried by queue)
        $this->expectException(\RuntimeException::class);

        $job = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, $batchId, $merkleRoot);
        $job->handle($mockService);

        // Logs should NOT be updated
        $log = Activity::first();
        assert($log instanceof Activity);
        $this->assertNull($log->ots_proof);
        $this->assertNull($log->ots_submitted_at);
    }

    public function test_job_is_queued_on_opentimestamp_queue(): void
    {
        Queue::fake();

        // Act: Dispatch job
        SubmitMerkleRootToOpenTimestamp::dispatch(1, 1000, 'abc123');

        // Assert: Job queued on correct queue
        Queue::assertPushedOn('opentimestamp', SubmitMerkleRootToOpenTimestamp::class);
    }

    public function test_job_skips_if_no_logs_found(): void
    {
        // Arrange: No logs with specified batch
        $batchId = 9999;
        $merkleRoot = hash('sha256', 'root');

        $mockService = $this->mock(OpenTimestampService::class);
        $mockService->shouldNotReceive('submit');

        // Act: Job should handle gracefully
        $job = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, $batchId, $merkleRoot);
        $job->handle($mockService);

        // Assert: No errors, no submissions
        $this->assertEquals(0, Activity::whereNotNull('ots_submitted_at')->count());
    }

    public function test_job_converts_binary_proof_for_storage(): void
    {
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

        $mockService = $this->mock(OpenTimestampService::class);
        $mockService->shouldReceive('submit')
            ->andReturn($binaryProof);

        // Act
        $job = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, $batchId, $merkleRoot);
        $job->handle($mockService);

        // Assert: Binary proof stored correctly
        $log = Activity::first();
        assert($log instanceof Activity);
        $this->assertNotNull($log->ots_proof);
        $this->assertEquals($binaryProof, $log->ots_proof);
    }
}
