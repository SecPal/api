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
use Illuminate\Support\Collection;

/**
 * Build Merkle tree batches for Level 2+3 activity logs.
 *
 * This job:
 * - Finds unbatched Level 2+3 logs per tenant
 * - Builds Merkle tree from event hashes
 * - Stores merkle_root, merkle_batch_id, merkle_proof
 * - Dispatches OpenTimestamp submission for Level 3
 *
 * Scheduled hourly for Level 2+3 activity logs (configurable via console schedule).
 *
 * @see ADR-010 Section 4: Merkle Tree Building
 * @see Issue #389 PR-4: Implement BuildMerkleTreeBatch job
 */
class BuildMerkleTreeBatch implements ShouldQueue
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
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('merkle');
    }

    /**
     * Execute the job.
     *
     * Finds all tenants with unbatched logs and builds Merkle trees.
     *
     * After refactoring: ALL log types get merkle tree + OTS
     * (not just Level 2+3). Retention period only affects deletion,
     * not cryptographic protection.
     */
    public function handle(): void
    {
        // Get ALL log names (retention-based, not level-based)
        $retentionYears = Activity::getRetentionYears();
        
        if (! is_array($retentionYears)) {
            return; // Invalid return type
        }
        
        $allLogNames = collect($retentionYears)
            ->keys()
            ->all();

        if (empty($allLogNames)) {
            return; // No log types configured
        }

        // Find tenants with unbatched logs
        $tenantIds = Activity::whereNull('merkle_root')
            ->whereIn('log_name', $allLogNames)
            ->distinct('tenant_id')
            ->pluck('tenant_id');

        // Build tree for each tenant
        foreach ($tenantIds as $tenantId) {
            if (! is_int($tenantId)) {
                continue; // Skip invalid tenant IDs
            }

            $this->buildTreeForTenant($tenantId, $allLogNames);
        }
    }

    /**
     * Build Merkle tree for a specific tenant.
     *
     * @param  int  $tenantId  Tenant ID
     * @param  array<string>  $logNames  All log names to batch
     */
    protected function buildTreeForTenant(int $tenantId, array $logNames): void
    {
        // Query unbatched logs for this tenant that have valid event_hash
        // CRITICAL: Only process logs with hash chain already built
        $logs = Activity::where('tenant_id', $tenantId)
            ->whereNull('merkle_root')
            ->whereIn('log_name', $logNames)
            ->whereNotNull('event_hash') // Skip logs without hash chain
            ->orderBy('created_at')
            ->orderBy('id') // Secondary sort for deterministic order
            ->get();

        if ($logs->isEmpty()) {
            return; // No logs to batch
        }

        // Generate unique batch ID (microsecond-precision timestamp for ordering)
        $batchId = (int) now()->getPreciseTimestamp(3);

        // Build Merkle tree
        $tree = $this->buildTree($logs);

        // Store the batch count for forensic integrity checking
        $batchCount = $logs->count();

        // Update logs with Merkle data
        foreach ($logs as $index => $log) {
            $log->update([
                'merkle_batch_id' => $batchId,
                'merkle_batch_count' => $batchCount,
                'merkle_root' => $tree['root'],
                'merkle_proof' => $tree['proofs'][$index],
            ]);
        }

        // Dispatch OpenTimestamp submission for ALL batches
        // All logs get identical security (Hash + Merkle + OTS),
        // regardless of retention period
        dispatch(new SubmitMerkleRootToOpenTimestamp(
            $tenantId,
            $batchId,
            $tree['root']
        ));
    }

    /**
     * Build Merkle tree from log collection.
     *
     * Algorithm:
     * 1. Extract event_hash as leaves
     * 2. Build tree bottom-up (pair-wise SHA256 hashing)
     * 3. Handle odd leaves by duplicating last leaf
     * 4. Generate proof for each leaf (sibling hashes + positions)
     *
     * @param  Collection<int, Activity>  $logs  Activity logs
     * @return array{root: string, proofs: array<int, array<int, array{hash: string, position: string}>>}
     */
    protected function buildTree(Collection $logs): array
    {
        // Extract leaves (event hashes)
        $leaves = $logs->pluck('event_hash')->all();
        $leafCount = count($leaves);

        if ($leafCount === 0) {
            throw new \RuntimeException('Cannot build Merkle tree: no leaves provided');
        }

        // Single leaf = root is the leaf itself
        if ($leafCount === 1) {
            $root = $leaves[0];
            if (! is_string($root)) {
                throw new \RuntimeException('Leaf hash must be string');
            }

            return [
                'root' => $root,
                'proofs' => [[]], // Empty proof for single node
            ];
        }

        // Initialize current level with leaves
        $currentLevel = $leaves;

        // Track which original leaf index maps to which node in current level
        $leafMapping = array_keys($leaves); // [0, 1, 2, 3]

        // Initialize proofs array
        $proofs = array_fill(0, $leafCount, []);

        // Build tree bottom-up until root
        while (count($currentLevel) > 1) {
            $nextLevel = [];
            $nextMapping = [];

            for ($i = 0; $i < count($currentLevel); $i += 2) {
                $leftHash = $currentLevel[$i];
                $rightHash = $currentLevel[$i + 1] ?? $leftHash; // Duplicate if odd

                if (! is_string($leftHash) || ! is_string($rightHash)) {
                    throw new \RuntimeException('Hash values must be strings');
                }

                // Hash parent
                $parentHash = hash('sha256', $leftHash.$rightHash);
                $nextLevel[] = $parentHash;

                // Record which leaves are under this parent
                $leftLeafIndices = is_array($leafMapping[$i]) ? $leafMapping[$i] : [$leafMapping[$i]];
                $rightLeafIndices = isset($leafMapping[$i + 1])
                    ? (is_array($leafMapping[$i + 1]) ? $leafMapping[$i + 1] : [$leafMapping[$i + 1]])
                    : $leftLeafIndices;

                // All leaves under left child get right hash as sibling
                foreach ($leftLeafIndices as $leafIdx) {
                    $proofs[$leafIdx][] = ['hash' => $rightHash, 'position' => 'right'];
                }

                // All leaves under right child get left hash as sibling
                if (isset($currentLevel[$i + 1])) {
                    foreach ($rightLeafIndices as $leafIdx) {
                        $proofs[$leafIdx][] = ['hash' => $leftHash, 'position' => 'left'];
                    }
                }

                // Merge leaf indices for next level, avoiding duplicates when the right child is duplicated
                if (isset($currentLevel[$i + 1])) {
                    $nextMapping[] = array_merge($leftLeafIndices, $rightLeafIndices);
                } else {
                    $nextMapping[] = $leftLeafIndices;
                }
            }

            $currentLevel = $nextLevel;
            $leafMapping = $nextMapping;
        }

        $root = $currentLevel[0];
        if (! is_string($root)) {
            throw new \RuntimeException('Merkle root must be string');
        }

        return [
            'root' => $root,
            'proofs' => $proofs,
        ];
    }
}
