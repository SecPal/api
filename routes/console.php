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
Schedule::job(new \App\Jobs\BuildMerkleTreeBatch)->hourly()->name('merkle-tree-batch');
