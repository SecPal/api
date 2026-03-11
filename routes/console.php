<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule: Expire temporal role assignments every minute
Schedule::command('roles:expire')->everyMinute();

// Schedule: Update employee statuses based on contract dates daily at 06:00
Schedule::command('employees:update-status')->dailyAt('06:00');

// Schedule: Update qualification statuses and send expiry notifications daily at 07:00
Schedule::command('employees:update-qualifications')->dailyAt('07:00');

// Schedule: Send contract ending soon notifications daily at 08:00
Schedule::command('employees:send-contract-ending-notifications')->dailyAt('08:00');

// Schedule: Build Merkle trees for Level 2+3 activity logs
// See ADR-010 Phase 2: Merkle Tree Building
// Frequency configured via MERKLE_SCHEDULE_FREQUENCY env var
// Default: every minute (local), hourly (production)
$merkleFrequency = config('opentimestamp.merkle_schedule_frequency', 'hour');
$merkleSchedule = $merkleFrequency === 'minute'
    ? Schedule::job(App\Jobs\BuildMerkleTreeBatch::class)->everyMinute()
    : Schedule::job(App\Jobs\BuildMerkleTreeBatch::class)->hourly();
$merkleSchedule->name('merkle-tree-batch');

// Schedule: Upgrade pending OpenTimestamp proofs hourly
// See ADR-010 Phase 3: OpenTimestamp Integration
// Checks for Bitcoin block confirmations and upgrades pending proofs
Schedule::job(App\Jobs\UpgradeOpenTimestampProofs::class)->hourly()->name('ots-proof-upgrade');

// Schedule: Apply retention policies daily at 02:00
// See ADR-010 Phase 4: Retention Policies
// 3-tier strategy: Level 1 (1y→2y), Level 2 (3y→5y), Level 3 (permanent)
// BewachV §21 Abs. 4 + GDPR Article 5(1)(e) compliance
Schedule::command('activity:apply-retention')->dailyAt('02:00')->name('activity-retention');

// Schedule: Monitor OpenTimestamp health every 6 hours
// Checks library version, calendar server availability, and functionality
// Logs warnings if servers are down or updates are available
Schedule::command('ots:monitor')->everySixHours()->name('ots-health-monitor');

// Schedule: Check for OpenTimestamp library updates weekly
// Runs every Monday at 03:00 to check for new versions
// Manual update with: php artisan ots:update
Schedule::command('ots:check --update-check')->weekly()->mondays()->at('03:00')->name('ots-update-check');
