<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// SAFE_PARALLEL: conditional deletion is the database-level winner; valid_until
// remains durable authority, so missed ticks are recovered without a scheduler lock.
Schedule::command('roles:expire')->everyMinute()->name('role-expiration');

// DURABLE_DUE_WORK_DISPATCHER: persisted contract dates are scanned with <= and
// lifecycle transactions revalidate state. One launch avoids duplicate notices.
Schedule::command('employees:update-status')
    ->dailyAt('06:00')
    ->name('employee-status-update')
    ->onOneServer()
    ->withoutOverlapping(120);

// DURABLE_DUE_WORK_DISPATCHER: persisted expiry dates drive status catch-up.
Schedule::command('employees:update-qualifications')
    ->dailyAt('07:00')
    ->name('qualification-status-update')
    ->onOneServer()
    ->withoutOverlapping(120);

// DEPLOYMENT_WIDE_SINGLE_LAUNCH: duplicate launches would enqueue duplicate mail.
Schedule::command('employees:send-contract-ending-notifications')
    ->dailyAt('08:00')
    ->name('contract-ending-notifications')
    ->onOneServer()
    ->withoutOverlapping(120);

// DEPLOYMENT_WIDE_SINGLE_LAUNCH: duplicate launches would enqueue duplicate mail.
Schedule::command('employees:send-compliance-alert-notifications')
    ->dailyAt('08:30')
    ->name('employee-compliance-notifications')
    ->onOneServer()
    ->withoutOverlapping(120);

// Schedule: Build Merkle trees for Level 2+3 activity logs
// See ADR-010 Phase 2: Merkle Tree Building
// Frequency configured via MERKLE_SCHEDULE_FREQUENCY env var
// Default: every minute (local), hourly (production)
$merkleFrequency = config('opentimestamp.merkle_schedule_frequency', 'hour');
$merkleSchedule = $merkleFrequency === 'minute'
    ? Schedule::job(App\Jobs\BuildMerkleTreeBatch::class)->everyMinute()
    : Schedule::job(App\Jobs\BuildMerkleTreeBatch::class)->hourly();
// DURABLE_DUE_WORK_DISPATCHER: unbatched activity rows are authoritative and the
// scheduled event only materializes database-queued work.
$merkleSchedule->name('merkle-tree-batch')->onOneServer();

// Schedule: Upgrade pending OpenTimestamp proofs hourly
// See ADR-010 Phase 3: OpenTimestamp Integration
// Checks for Bitcoin block confirmations and upgrades pending proofs
// DURABLE_DUE_WORK_DISPATCHER: pending proof rows remain queryable after missed ticks.
Schedule::job(App\Jobs\UpgradeOpenTimestampProofs::class)
    ->hourly()
    ->name('ots-proof-upgrade')
    ->onOneServer();

// Schedule: Apply retention policies daily at 02:00
// See ADR-010 Phase 4: Retention Policies
// 3-tier strategy: Level 1 (1y→2y), Level 2 (3y→5y), Level 3 (permanent)
// BewachV §21 Abs. 4 + GDPR Article 5(1)(e) compliance
// OVERLAP_PROTECTED: legal retention mutation must not overlap, while persisted
// creation timestamps and transactions remain correctness authority after expiry.
Schedule::command('activity:apply-retention')
    ->dailyAt('02:00')
    ->name('activity-retention')
    ->onOneServer()
    ->withoutOverlapping(180);

// Schedule: Delete expired terminated employee data daily at 02:30
// Runs after activity retention so the deletion event itself starts a fresh retention window.
// OVERLAP_PROTECTED: database and object-storage deletion must not overlap;
// retention_period_end remains durable catch-up authority.
Schedule::command('employees:delete-expired')
    ->dailyAt('02:30')
    ->name('employee-retention-deletion')
    ->onOneServer()
    ->withoutOverlapping(180);

// Schedule: Monitor OpenTimestamp health every 6 hours
// Checks library version, calendar server availability, and functionality
// Logs warnings if servers are down or updates are available
// DEPLOYMENT_WIDE_SINGLE_LAUNCH: one shared external health observation is enough.
Schedule::command('ots:monitor')
    ->everySixHours()
    ->name('ots-health-monitor')
    ->onOneServer();

// Schedule: Check for OpenTimestamp library updates weekly
// Runs every Monday at 03:00 to check for new versions
// Manual update with: php artisan ots:update
// DEPLOYMENT_WIDE_SINGLE_LAUNCH: one external version check is enough per interval.
Schedule::command('ots:check --update-check')
    ->weekly()
    ->mondays()
    ->at('03:00')
    ->name('ots-update-check')
    ->onOneServer();

// OpenPLZ German street reference data (ODbL). Hash-based runs skip quickly when unchanged.
// OVERLAP_PROTECTED: download/import mutates the shared reference dataset; the
// persisted source hash makes a later run recoverable and skips unchanged data.
Schedule::command('addresses:import')
    ->weekly()
    ->mondays()
    ->at('03:30')
    ->name('address-data-update')
    ->onOneServer()
    ->withoutOverlapping(180);
