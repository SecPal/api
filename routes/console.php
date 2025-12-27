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

// Schedule: Build Merkle trees for Level 2+3 activity logs hourly
// See ADR-010 Phase 2: Merkle Tree Building
Schedule::job(\App\Jobs\BuildMerkleTreeBatch::class)->hourly()->name('merkle-tree-batch');

// Schedule: Upgrade pending OpenTimestamp proofs hourly
// See ADR-010 Phase 3: OpenTimestamp Integration
// Checks for Bitcoin block confirmations and upgrades pending proofs
Schedule::job(\App\Jobs\UpgradeOpenTimestampProofs::class)->hourly()->name('ots-proof-upgrade');

// Schedule: Apply retention policies daily at 02:00
// See ADR-010 Phase 4: Retention Policies
// 3-tier strategy: Level 1 (1y→2y), Level 2 (3y→5y), Level 3 (permanent)
// BewachV §21 Abs. 4 + GDPR Article 5(1)(e) compliance
Schedule::command('activity:apply-retention')->dailyAt('02:00')->name('activity-retention');
