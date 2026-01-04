<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled monitoring task for OpenTimestamp health
 */
class MonitorOpenTimestamp extends Command
{
    protected $signature = 'ots:monitor
                          {--alert-threshold=2 : Number of failed servers before alerting}';

    protected $description = 'Monitor OpenTimestamp health and alert on issues';

    public function handle(): int
    {
        $threshold = (int) $this->option('alert-threshold');

        // Run the check command and capture results
        $this->info('Running OpenTimestamp health check...');

        $exitCode = $this->call('ots:check', ['--json' => true]);

        if ($exitCode !== 0) {
            $this->error('⚠ OpenTimestamp health check FAILED');

            // Log the failure
            Log::critical('OpenTimestamp health check failed', [
                'component' => 'opentimestamp',
                'check_type' => 'scheduled_monitor',
            ]);

            // TODO: Send notification (email, Slack, etc.)
            // You could integrate with Laravel notifications here

            return self::FAILURE;
        }

        // Check for pending activities without OTS proofs
        $pendingCount = Activity::whereNotNull('merkle_root')
            ->whereNull('ots_proof')
            ->count();

        if ($pendingCount > 100) {
            $this->warn("⚠ {$pendingCount} activities without OTS proof");

            Log::warning('High number of activities without OTS proof', [
                'component' => 'opentimestamp',
                'pending_count' => $pendingCount,
            ]);
        }

        $this->info('✓ OpenTimestamp monitoring complete');

        return self::SUCCESS;
    }
}
