<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Submit Merkle root to OpenTimestamp calendar servers (STUB).
 *
 * This is a placeholder job for Issue #391 (PR-6: OpenTimestamp Integration).
 * It is referenced by BuildMerkleTreeBatch but not yet implemented.
 *
 * Full implementation will:
 * - Submit merkle_root to OTS calendar servers
 * - Store pending OTS proof in database
 * - Update ots_submitted_at timestamp
 *
 * @see Issue #391 PR-6: Integrate OpenTimestamp PHP library
 * @see ADR-010 Phase 3: OpenTimestamp Integration
 */
class SubmitMerkleRootToOpenTimestamp implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param  int  $tenantId  Tenant ID
     * @param  string  $batchId  Merkle batch UUID
     * @param  string  $merkleRoot  Merkle root hash
     */
    public function __construct(
        public int $tenantId,
        public string $batchId,
        public string $merkleRoot
    ) {
        $this->onQueue('opentimestamp');
    }

    /**
     * Execute the job (STUB).
     *
     * Will be implemented in Issue #391.
     */
    public function handle(): void
    {
        // STUB: Will be implemented in PR-6
        // - Install opentimestamps/php-opentimestamps
        // - Submit merkleRoot to OTS calendar
        // - Store pending proof in activity_log
    }
}
