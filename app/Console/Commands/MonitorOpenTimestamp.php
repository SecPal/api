<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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
    /**
     * Threshold for alerting when too many activities are pending OTS proof submission.
     */
    private const PENDING_ACTIVITIES_ALERT_THRESHOLD = 100;

    protected $signature = 'ots:monitor';

    protected $description = 'Monitor OpenTimestamp health and alert on issues';

    /**
     * Execute the OpenTimestamp monitoring command.
     *
     * This command:
     * - Runs the ots:check health check command and logs a critical error if the check fails.
     * - Evaluates the number of activities that have a Merkle root but no OTS proof
     *   and logs a warning when this count exceeds the alert threshold.
     *
     * @return int Console exit code: self::SUCCESS on success or self::FAILURE if the health check command fails.
     */
    public function handle(): int
    {

        // Run the check command and capture results
        $this->info('Running OpenTimestamp health check...');

        $exitCode = $this->call('ots:check');

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

        if ($pendingCount > self::PENDING_ACTIVITIES_ALERT_THRESHOLD) {
            $this->warn("⚠ {$pendingCount} activities without OTS proof");

            Log::warning('High number of activities without OTS proof', [
                'component' => 'opentimestamp',
                'pending_count' => $pendingCount,
                'threshold' => self::PENDING_ACTIVITIES_ALERT_THRESHOLD,
            ]);
        }

        $this->info('✓ OpenTimestamp monitoring complete');

        return self::SUCCESS;
    }
}
