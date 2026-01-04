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
 * Upgrade pending OpenTimestamp proofs to confirmed proofs.
 *
 * Queries pending proofs and attempts to upgrade them when Bitcoin blocks confirm.
 * Updates ots_confirmed_at when proof is successfully upgraded.
 *
 * Scheduled hourly via routes/console.php.
 *
 * @see ADR-010 Section 6: OpenTimestamp Integration
 * @see Issue #391 PR-6: Integrate OpenTimestamp PHP library
 */
class UpgradeOpenTimestampProofs implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1; // Don't retry - will run again hourly

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes for large batches

    /**
     * Maximum number of proofs to process per job run.
     *
     * Limits batch size to prevent long-running jobs and memory issues.
     * With hourly scheduling, this allows up to 2400 upgrades per day.
     */
    private const BATCH_LIMIT = 100;

    /**
     * Minimum age (in hours) before attempting to upgrade a proof.
     *
     * Bitcoin blocks confirm every ~10 minutes, but calendar servers may need
     * additional time to aggregate. Waiting 1 hour reduces unnecessary checks.
     */
    private const MIN_AGE_HOURS = 1;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('opentimestamp');
    }

    /**
     * Execute the job.
     *
     * OpenTimestamp Proof Upgrade Workflow:
     * -------------------------------------
     * 1. Query pending proofs (submitted but not confirmed)
     * 2. Skip recently submitted proofs (<1 hour old) - unlikely to be ready
     * 3. Limit batch size to 100 proofs (prevent long-running jobs)
     * 4. For each proof: call OTS upgrade command (checks calendar + Bitcoin)
     * 5. If confirmed: Update with Bitcoin attestation, set ots_confirmed_at
     * 6. If still pending: Skip, will retry next hour
     * 7. If error: Log warning, continue (don't block other upgrades)
     *
     * Pending → Confirmed Transition:
     *   - Pending proof has calendar attestations (magic bytes 0x04f0...)
     *   - Calendar servers aggregate to Bitcoin transaction
     *   - Bitcoin block confirms (~10-60 minutes after submission)
     *   - Upgrade fetches Bitcoin attestation from calendars
     *   - Confirmed proof has Bitcoin block hash (magic bytes 0x05889...)
     *
     * Batch Processing Strategy:
     *   - Process oldest proofs first (FIFO)
     *   - Limit to 100 per run (hourly = up to 2400/day capacity)
     *   - Skip recent (<1h) to reduce calendar load
     *   - Continue on individual errors (don't fail entire batch)
     *
     * @see \App\Jobs\SubmitMerkleRootToOpenTimestamp for proof creation
     * @see \App\Services\OpenTimestampService::upgrade() for upgrade logic
     * @see ADR-010 Section 6: OpenTimestamp Integration
     */
    public function handle(OpenTimestampService $otsService): void
    {
        Log::info('UpgradeOpenTimestampProofs: Starting');

        // Find pending proofs ready for upgrade
        // - Skip recently submitted (<1h) - unlikely to be ready yet
        // - Limit to 100 to prevent long-running jobs
        // - Process oldest first (FIFO fairness)
        $pendingLogs = Activity::whereNotNull('ots_submitted_at')
            ->whereNull('ots_confirmed_at')
            ->whereNotNull('ots_proof')
            ->where('ots_submitted_at', '<=', now()->subHours(self::MIN_AGE_HOURS))
            ->orderBy('ots_submitted_at') // Oldest first
            ->limit(self::BATCH_LIMIT)
            ->get();

        if ($pendingLogs->isEmpty()) {
            Log::info('UpgradeOpenTimestampProofs: No pending proofs to upgrade');

            return;
        }

        Log::info('UpgradeOpenTimestampProofs: Found pending proofs', [
            'count' => $pendingLogs->count(),
        ]);

        $upgraded = 0;
        $failed = 0;
        $stillPending = 0;
        $confirmedAt = now();
        $toConfirm = [];

        foreach ($pendingLogs as $log) {
            try {
                // Skip if proof is null (shouldn't happen due to query filter, but PHPStan safety)
                if ($log->ots_proof === null) {
                    continue;
                }

                $upgradedProof = $otsService->upgrade($log->ots_proof);

                if ($upgradedProof !== null) {
                    // Note: upgrade() returns binary proof (Activity mutator will base64-encode on save)
                    $toConfirm[$log->id] = $upgradedProof;
                    $upgraded++;

                    Log::debug('UpgradeOpenTimestampProofs: Proof upgraded', [
                        'activity_id' => $log->id,
                        'tenant_id' => $log->tenant_id,
                    ]);
                } else {
                    // Not yet confirmed (Bitcoin block not mined yet)
                    $stillPending++;
                }
            } catch (\Exception $e) {
                $failed++;

                Log::warning('UpgradeOpenTimestampProofs: Upgrade failed', [
                    'activity_id' => $log->id,
                    'tenant_id' => $log->tenant_id,
                    'error' => $e->getMessage(),
                ]);

                // Continue processing other logs (don't fail entire job)
            }
        }

        // Bulk update all confirmed proofs
        if ($toConfirm !== []) {
            foreach ($toConfirm as $logId => $upgradedProofBinary) {
                // Encode binary proof to base64 for storage (bypass mutator)
                $encodedProof = base64_encode($upgradedProofBinary);

                // Update directly in database (bypasses mutator)
                \Illuminate\Support\Facades\DB::table('activity_log')
                    ->where('id', $logId)
                    ->update([
                        'ots_proof' => $encodedProof,
                        'ots_confirmed_at' => $confirmedAt,
                        'updated_at' => $confirmedAt,
                    ]);
            }
        }

        Log::info('UpgradeOpenTimestampProofs: Completed', [
            'processed' => $pendingLogs->count(),
            'upgraded' => $upgraded,
            'still_pending' => $stillPending,
            'failed' => $failed,
            'batch_limit' => self::BATCH_LIMIT,
            'min_age_hours' => self::MIN_AGE_HOURS,
        ]);

        // Log metrics for monitoring
        if ($failed > 0) {
            Log::warning('UpgradeOpenTimestampProofs: Some upgrades failed', [
                'failed_count' => $failed,
                'success_rate' => round(($upgraded / $pendingLogs->count()) * 100, 2).'%',
            ]);
        }
    }
}
