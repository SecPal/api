<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Activity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Process activity log hash chain building sequentially per tenant.
 *
 * This job eliminates race conditions in hash chain building by ensuring
 * sequential processing of activity logs within each tenant's scope.
 *
 * PROBLEM SOLVED (Issue #408):
 * - Previous implementation: buildHashChain() in 'creating' hook (BEFORE INSERT)
 * - Race window: Concurrent Activity::create() calls could read same previous_hash
 * - Result: Broken hash chains under high load (>10 logs/sec per tenant)
 *
 * SOLUTION:
 * - Queue-based sequential processing per tenant
 * - Each job builds hash chain atomically within transaction
 * - Guarantees 100% race-free hash chain building
 *
 * ARCHITECTURE:
 * - Queue: 'activity-hash-chain' (dedicated queue for sequential processing)
 * - Timeout: 60 seconds (sufficient for hash calculation + DB insert)
 * - Retries: 3 attempts with exponential backoff
 * - Failure handling: Re-throws exception to trigger retry
 *
 * @see Activity::buildHashChain() - Legacy synchronous implementation (removed)
 * @see Issue #408 Queue-based Activity Hash Chain Building
 * @see Epic #385 Activity Logging & Audit Trail
 * @see Issue #402 Original security & locking implementation
 */
class ProcessActivityHashChain implements ShouldQueue
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
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @param  int  $tenantId  Tenant ID for hash chain isolation
     * @param  array<string, mixed>  $activityData  Activity log data (attributes to be inserted)
     */
    public function __construct(
        public int $tenantId,
        public array $activityData
    ) {
        $this->onQueue('activity-hash-chain');
    }

    /**
     * Calculate the backoff delays for retries.
     *
     * Uses exponential backoff: 1s, 2s, 4s between retry attempts.
     *
     * @return array<int, int> Array of delay times in seconds
     */
    public function backoff(): array
    {
        return [1, 2, 4];
    }

    /**
     * Execute the job.
     *
     * Hash Chain Building Workflow:
     * -----------------------------
     * 1. Start database transaction (ensures atomicity)
     * 2. Find previous activity log for tenant (with lockForUpdate)
     * 3. Calculate hash: SHA256(previous_hash + activity_data)
     * 4. Update activity log with computed hash (activity already exists in DB)
     * 5. Commit transaction
     *
     * RACE CONDITION ELIMINATION:
     * - Queue ensures sequential execution per tenant
     * - lockForUpdate() prevents concurrent reads within transaction
     * - Transaction scope includes UPDATE (activity already inserted by 'created' hook)
     * - Result: 100% atomic hash chain building
     *
     * @throws \RuntimeException if tenant_id is missing or hash calculation fails
     */
    public function handle(): void
    {
        Log::info('ProcessActivityHashChain: Starting', [
            'tenant_id' => $this->tenantId,
            'activity_id' => $this->activityData['id'] ?? null,
            'attempt' => $this->attempts(),
        ]);

        // Validate activity ID
        if (! isset($this->activityData['id'])) {
            throw new \RuntimeException('Cannot build hash chain: activity_id is required.');
        }

        $activityId = $this->activityData['id'];

        // Build hash chain and update activity within transaction
        // Use advisory lock to ensure sequential processing per tenant
        DB::transaction(function () use ($activityId): void {
            // Acquire exclusive advisory lock for this tenant
            // This ensures only ONE job per tenant processes at a time
            // Lock is automatically released when transaction commits/rolls back
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$this->tenantId]);

            // Find previous log in tenant's chain
            // Exclude current activity from lookup
            // lockForUpdate() ensures no other transaction can modify the row
            $previousActivity = Activity::where('tenant_id', $this->tenantId)
                ->where('id', '!=', $activityId)
                ->lockForUpdate() // Row-level lock (SELECT ... FOR UPDATE)
                ->latest('created_at')
                ->latest('id') // Tie-breaker for microsecond precision
                ->first();

            // Compute previous_hash
            $previousHash = $previousActivity?->event_hash;

            // Calculate event_hash: SHA256(previous_hash + log_data)
            try {
                $logData = json_encode([
                    'tenant_id' => $this->activityData['tenant_id'] ?? null,
                    'log_name' => $this->activityData['log_name'] ?? null,
                    'description' => $this->activityData['description'] ?? null,
                    'subject_type' => $this->activityData['subject_type'] ?? null,
                    'subject_id' => $this->activityData['subject_id'] ?? null,
                    'causer_type' => $this->activityData['causer_type'] ?? null,
                    'causer_id' => $this->activityData['causer_id'] ?? null,
                    'properties' => $this->activityData['properties'] ?? null,
                    'created_at' => $this->activityData['created_at'] ?? null, // Timestamp ensures hash uniqueness
                ], JSON_THROW_ON_ERROR);

                $eventHash = hash('sha256', ($previousHash ?? '').$logData);
            } catch (\JsonException $exception) {
                throw new \RuntimeException('Failed to encode activity log data for hashing.', 0, $exception);
            }

            // Update activity log with hash values
            // Use DB::table() for raw update (avoids triggering model events again)
            DB::table('activity_log')
                ->where('id', $activityId)
                ->update([
                    'previous_hash' => $previousHash,
                    'event_hash' => $eventHash,
                ]);

            Log::info('ProcessActivityHashChain: Success', [
                'tenant_id' => $this->tenantId,
                'activity_id' => $activityId,
                'previous_hash' => $previousHash,
                'event_hash' => $eventHash,
            ]);
        });
    }
}
