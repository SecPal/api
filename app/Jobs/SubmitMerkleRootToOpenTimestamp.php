<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
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
 * Updates ALL activity logs in the batch with the pending proof.
 *
 * Dispatched by BuildMerkleTreeBatch job after Merkle tree is built.
 *
 * @see ADR-010 Section 6: OpenTimestamp Integration
 * @see Issue #391 PR-6: Integrate OpenTimestamp PHP library
 * @see Issue #441: Retention refactoring - ALL logs now get OTS
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
     * Calculate the backoff delays for retries.
     *
     * Uses exponential backoff: 1s, 2s, 4s between retry attempts.
     * This prevents overwhelming calendar servers during temporary outages.
     *
     * @return array<int, int> Array of delay times in seconds [1, 2, 4]
     */
    public function backoff(): array
    {
        return [1, 2, 4];
    }

    /**
     * Execute the job.
     *
     * OpenTimestamp Workflow:
     * -----------------------
     * 1. Submit Merkle root to multiple calendar servers (CalendarAsyncSubmit)
     * 2. Calendars aggregate submissions and create pending timestamp
     * 3. Store pending OTS proof (contains calendar attestations)
     * 4. Later, UpgradeOpenTimestampProofs job checks if Bitcoin block confirmed
     * 5. When confirmed, proof is upgraded with Bitcoin attestation
     *
     * Pending proofs (magic bytes 0x04f0...):
     *   - Contain calendar server attestations
     *   - Not yet anchored to Bitcoin blockchain
     *   - Can be upgraded after ~10-60 minutes
     *
     * Confirmed proofs (magic bytes 0x05889...):
     *   - Contain Bitcoin block attestation
     *   - Immutable blockchain anchor
     *   - Can be verified independently forever
     *
     * This job creates PENDING proofs. The UpgradeOpenTimestampProofs job
     * (scheduled hourly) converts pending → confirmed when Bitcoin confirms.
     *
     * @see UpgradeOpenTimestampProofs for proof upgrade
     * @see OpenTimestampService::submit() for calendar submission
     * @see ADR-010 Section 6: OpenTimestamp Integration
     *
     * @throws \RuntimeException if submission fails (triggers retry with backoff)
     */
    public function handle(OpenTimestampService $otsService): void
    {
        Log::info('SubmitMerkleRootToOpenTimestamp: Starting', [
            'tenant_id' => $this->tenantId,
            'batch_id' => $this->batchId,
            'merkle_root' => $this->merkleRoot,
            'attempt' => $this->attempts(),
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

        // Update all logs in batch with pending proof (bulk update for performance)
        $submittedAt = now();
        $logIds = $logs->pluck('id')->all();

        // Base64 encode proof for storage (matches Activity accessor/mutator)
        $encodedProof = base64_encode($proof);

        Activity::whereIn('id', $logIds)->update([
            'ots_proof' => $encodedProof,
            'ots_submitted_at' => $submittedAt,
        ]);

        Log::info('SubmitMerkleRootToOpenTimestamp: Completed', [
            'tenant_id' => $this->tenantId,
            'batch_id' => $this->batchId,
            'logs_updated' => $logs->count(),
        ]);
    }
}
