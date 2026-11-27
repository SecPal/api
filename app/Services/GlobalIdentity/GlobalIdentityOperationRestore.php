<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class GlobalIdentityOperationRestore
{
    public function __construct(
        private GlobalIdentityOperationService $operations,
        private GlobalIdentityJournalAuthenticator $authenticator,
    ) {}

    /** Invoke in the isolated restore before any identity or delivery entry point starts. */
    public function quarantine(): void
    {
        $this->requireOutsideTransaction();
        $this->operations->quarantineRestoredOperations();
    }

    /**
     * The recovery owner supplies the restored database boundary and the latest
     * independently observed journal sequence. The candidate interval supplies neither.
     * Enter quarantine before evaluating even a missing or rejected checkpoint.
     */
    public function reconcile(
        string $backupEpoch,
        int $backupSequence,
        int $latestSequence,
        ?GlobalIdentityJournalInterval $interval,
    ): bool {
        $this->quarantine();
        if ($interval === null || ! GlobalIdentityOperationContext::isCanonicalId($backupEpoch)
            || $interval->backupEpoch !== $backupEpoch || $interval->backupSequence !== $backupSequence
            || $interval->checkpointSequence !== $latestSequence || ! $interval->isContinuous()) {
            Log::warning('Global identity restore rejected.', ['reason' => 'incomplete_checkpoint']);

            return false;
        }
        try {
            $verified = $this->authenticator->verifies($interval->checkpoint(), $interval->authenticator);
        } catch (Throwable) {
            $verified = false;
        }
        if (! $verified) {
            Log::warning('Global identity restore rejected.', ['reason' => 'checkpoint_authentication']);

            return false;
        }

        // A terminal-only interval can tombstone a row. An absent termination
        // does not prove that a later dispatch never reached an external provider.
        $this->operations->applyRestoreTombstones($interval->entries);

        return true;
    }

    private function requireOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new GlobalIdentityKeyException('Global identity restore requires an isolated committed database.');
        }
    }
}
