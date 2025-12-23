<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Activity;
use App\Services\OpenTimestampService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Submit Merkle root to OpenTimestamp calendar servers.
 *
 * Creates pending proof that will be upgraded when Bitcoin block confirms.
 * Updates all Level 3 activity logs in the batch with the pending proof.
 *
 * Dispatched by BuildMerkleTreeBatch job after Merkle tree is built.
 *
 * @see ADR-010 Section 6: OpenTimestamp Integration
 * @see Issue #391 PR-6: Integrate OpenTimestamp PHP library
 */
class SubmitMerkleRootToOpenTimestamp implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * Create a new job instance.
     *
     * @param  int  $tenantId  Tenant ID
     * @param  int  $batchId  Merkle batch ID
     * @param  string  $merkleRoot  Merkle root hash (64 hex characters)
     */
    public function __construct(
        public int $tenantId,
        public int $batchId,
        public string $merkleRoot
    ) {
        $this->onQueue('opentimestamp');
    }

    /**
     * Execute the job.
     *
     * Submits Merkle root to OpenTimestamp calendars and stores pending proof.
     *
     * @throws \RuntimeException if submission fails
     */
    public function handle(OpenTimestampService $otsService): void
    {
        Log::info('SubmitMerkleRootToOpenTimestamp: Starting', [
            'tenant_id' => $this->tenantId,
            'batch_id' => $this->batchId,
            'merkle_root' => $this->merkleRoot,
        ]);

        // Find all logs in this batch
        $logs = Activity::where('tenant_id', $this->tenantId)
            ->where('merkle_batch_id', $this->batchId)
            ->where('merkle_root', $this->merkleRoot)
            ->whereNull('ots_submitted_at')
            ->get();

        if ($logs->isEmpty()) {
            Log::info('SubmitMerkleRootToOpenTimestamp: No logs to process', [
                'tenant_id' => $this->tenantId,
                'batch_id' => $this->batchId,
            ]);

            return;
        }

        // Submit Merkle root to calendars
        try {
            $proof = $otsService->submit($this->merkleRoot);

            Log::info('SubmitMerkleRootToOpenTimestamp: Submission successful', [
                'tenant_id' => $this->tenantId,
                'batch_id' => $this->batchId,
                'log_count' => $logs->count(),
                'proof_size' => strlen($proof),
            ]);
        } catch (\Exception $e) {
            Log::error('SubmitMerkleRootToOpenTimestamp: Submission failed', [
                'tenant_id' => $this->tenantId,
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Re-throw to trigger job retry
        }

        // Update all logs in batch with pending proof
        $submittedAt = now();

        foreach ($logs as $log) {
            $log->update([
                'ots_proof' => $proof,
                'ots_submitted_at' => $submittedAt,
            ]);
        }

        Log::info('SubmitMerkleRootToOpenTimestamp: Completed', [
            'tenant_id' => $this->tenantId,
            'batch_id' => $this->batchId,
            'logs_updated' => $logs->count(),
        ]);
    }
}
