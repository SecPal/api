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
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('opentimestamp');
    }

    /**
     * Execute the job.
     *
     * Finds all pending proofs and attempts upgrade.
     */
    public function handle(OpenTimestampService $otsService): void
    {
        Log::info('UpgradeOpenTimestampProofs: Starting');

        // Find all logs with pending proofs (submitted but not confirmed)
        $pendingLogs = Activity::whereNotNull('ots_submitted_at')
            ->whereNull('ots_confirmed_at')
            ->whereNotNull('ots_proof')
            ->orderBy('ots_submitted_at') // Oldest first
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
                    // Collect for bulk update
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
            foreach ($toConfirm as $logId => $upgradedProof) {
                // Base64 encode proof for storage (matches Activity accessor/mutator)
                $encodedProof = base64_encode($upgradedProof);

                Activity::where('id', $logId)->update([
                    'ots_proof' => $encodedProof,
                    'ots_confirmed_at' => $confirmedAt,
                ]);
            }
        }

        Log::info('UpgradeOpenTimestampProofs: Completed', [
            'total' => $pendingLogs->count(),
            'upgraded' => $upgraded,
            'still_pending' => $stillPending,
            'failed' => $failed,
        ]);
    }
}
