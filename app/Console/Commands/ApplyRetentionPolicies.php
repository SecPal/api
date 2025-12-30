<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\ActivityArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Apply retention policies to activity logs according to ADR-010.
 *
 * Calendar Year Retention Strategy (BewachV §21 Abs. 4 compliant):
 *
 * 3 Years (Bewachungsverordnung §21 Abs. 4):
 * - Log types: default, employee_changes, shift_management, security, authentication, rbac_changes
 * - Example: Created 2022-03-15 → Delete from 2026-01-01 (end of 2025 + 1 day)
 * - Marks next log as orphaned genesis when predecessor deleted
 *
 * 8 Years (HGB §257 Abs. 1 Nr. 4 - Buchungsbelege):
 * - Log types: invoices_created, invoices_payments, audit_reports
 * - Example: Created 2020-06-10 → Delete from 2029-01-01
 * - Preserves merkle tree integrity via orphaned genesis markers
 *
 * 10 Years (AO §147 Abs. 1 Nr. 1 - Jahresabschlüsse):
 * - Log types: financial_year_end, balance_sheets, annual_reports
 * - Example: Created 2019-12-31 → Delete from 2030-01-01
 * - Full hash chain + merkle tree + OpenTimestamp verification
 *
 * GDPR Article 5(1)(e) - Storage Limitation:
 * Personal data deleted, cryptographic hashes retained for verification.
 *
 * @see ADR-010 Section 5: Retention & Archiving
 * @see Issue #441 PR-15: Retention refactoring (security levels → retention periods)
 */
class ApplyRetentionPolicies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activity:apply-retention
                            {--dry-run : Preview actions without executing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply calendar year retention policies (3/8/10 years per BewachV/HGB/AO)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting retention policy application...');
        $this->info('Dry run: '.($this->option('dry-run') ? 'YES' : 'NO'));

        $statistics = [
            'retention_3_deleted' => 0,
            'retention_8_deleted' => 0,
            'retention_10_deleted' => 0,
            'orphaned_created' => 0,
        ];

        try {
            // Process all retention periods (3, 8, 10 years)
            $allRetentionYears = Activity::getRetentionYears();

            if (! is_array($allRetentionYears)) {
                throw new \RuntimeException('Activity::getRetentionYears() must return an array');
            }

            foreach ($allRetentionYears as $logName => $retentionYears) {
                $this->info('');
                $this->info("=== Processing '{$logName}' ({$retentionYears} years retention) ===");

                $deleted = $this->handleRetentionForLogType($logName, $retentionYears, $statistics);

                $key = "retention_{$retentionYears}_deleted";
                $statistics[$key] = ($statistics[$key] ?? 0) + $deleted;
            }

            $this->displayStatistics($statistics);

            $this->info('');
            $this->info('✅ Retention policies applied successfully.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Error applying retention policies: '.$e->getMessage());
            Log::error('Retention policy application failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Handle retention policy for a specific log type.
     *
     * Implements calendar year retention per BewachV §21 Abs. 4:
     * - Created 2022-03-15 + 3 years → delete from 2026-01-01
     * - Keep until end of Nth following calendar year
     *
     * @param  string  $logName  The log_name to process
     * @param  int  $retentionYears  Number of years to retain (3, 8, or 10)
     * @param  array<string, int>  $statistics  Statistics array (passed by reference)
     * @return int Number of logs deleted
     */
    protected function handleRetentionForLogType(string $logName, int $retentionYears, array &$statistics): int
    {
        // Calculate cutoff date: created_at + N years, end of calendar year, +1 day, midnight
        // Example: 2022-03-15 + 3y = 2025-03-15 → endOfYear() = 2025-12-31 → +1d = 2026-01-01 00:00:00
        $cutoffDate = now()->subYears($retentionYears)->endOfYear()->addDay()->startOfDay();

        $query = Activity::where('log_name', $logName)
            ->where('created_at', '<', $cutoffDate)
            ->whereNull('deleted_at');

        $count = (int) $query->count();

        if ($this->option('dry-run')) {
            $this->line("Would delete {$count} '{$logName}' logs older than {$cutoffDate->format('Y-m-d')}");

            return $count;
        }

        $orphaned = 0;

        $query->chunk(100, function ($logs) use (&$orphaned) {
            foreach ($logs as $log) {
                // Mark next log as orphaned genesis if it exists
                $nextLog = Activity::where('previous_hash', $log->event_hash)
                    ->whereNull('deleted_at')
                    ->first();

                if ($nextLog !== null) {
                    $nextLog->update([
                        'previous_hash' => null,
                        'is_orphaned_genesis' => true,
                        'orphaned_reason' => "Predecessor deleted (retention: {$log->log_name}, {$log->created_at->format('Y-m-d')})",
                        'orphaned_at' => now(),
                    ]);
                    $orphaned++;
                }

                $log->forceDelete();
            }
        });

        $statistics['orphaned_created'] += $orphaned;

        $this->info("✓ Deleted {$count} '{$logName}' logs older than {$cutoffDate->format('Y-m-d')}");
        if ($orphaned > 0) {
            $this->info("  → Created {$orphaned} orphaned genesis markers");
        }

        return $count;
    }

    /**
     * Display retention statistics.
     *
     * @param  array<string, int>  $statistics
     */
    protected function displayStatistics(array $statistics): void
    {
        $this->info('');
        $this->info('=== Retention Statistics ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['3-year retention: Deleted', $statistics['retention_3_deleted'] ?? 0],
                ['8-year retention: Deleted', $statistics['retention_8_deleted'] ?? 0],
                ['10-year retention: Deleted', $statistics['retention_10_deleted'] ?? 0],
                ['Orphaned genesis created', $statistics['orphaned_created']],
                ['Total actions', array_sum($statistics)],
            ]
        );
    }
}
