<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Apply retention policies to activity logs according to ADR-010.
 *
 * MULTI-TENANT: Processes ALL tenants independently with full isolation.
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
                            {--tenant= : Process specific tenant only (for testing)}
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
        $this->info('🔄 Starting Retention Policy Application');
        $this->info('');

        // Get tenants to process
        $tenantFilter = $this->option('tenant');
        $tenantIds = $tenantFilter
            ? collect([$tenantFilter])
            : Activity::distinct('tenant_id')->pluck('tenant_id');

        if ($tenantIds->isEmpty()) {
            $this->info('No tenants with activity logs found.');

            return Command::SUCCESS;
        }

        $this->info("Processing {$tenantIds->count()} tenant(s)...");
        $this->info('');

        $globalStatistics = [
            'retention_3_deleted' => 0,
            'retention_8_deleted' => 0,
            'retention_10_deleted' => 0,
            'orphaned_created' => 0,
        ];

        try {
            // Process each tenant independently
            foreach ($tenantIds as $tenantId) {
                // Cast to int for type safety (pluck returns string)
                $tenantId = (int) $tenantId;
                $this->info("=== Tenant: {$tenantId} ===");

                $tenantStats = $this->processTenant($tenantId);

                // Aggregate stats
                foreach ($tenantStats as $key => $value) {
                    $globalStatistics[$key] = ($globalStatistics[$key] ?? 0) + $value;
                }

                $this->info('');
            }

            $this->displayStatistics($globalStatistics);

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
     * Process retention policies for a single tenant.
     *
     * @return array<string, int> Statistics for this tenant
     */
    protected function processTenant(int $tenantId): array
    {
        $statistics = [
            'retention_3_deleted' => 0,
            'retention_8_deleted' => 0,
            'retention_10_deleted' => 0,
            'orphaned_created' => 0,
        ];

        // Process all retention periods (3, 8, 10 years)
        $allRetentionYears = Activity::getRetentionYears();

        if (! is_array($allRetentionYears)) {
            throw new \RuntimeException('Activity::getRetentionYears() must return an array');
        }

        // Group log types by retention period to optimize queries
        // Before: N×M queries (100 tenants × 17 log types = 1700 queries)
        // After: N×3 queries (100 tenants × 3 retention periods = 300 queries)
        $logTypesByRetention = [];
        foreach ($allRetentionYears as $logName => $years) {
            $logTypesByRetention[$years][] = $logName;
        }

        foreach ($logTypesByRetention as $retentionYears => $logNames) {
            $deleted = $this->handleRetentionForLogTypes($tenantId, $logNames, $retentionYears, $statistics);

            $key = "retention_{$retentionYears}_deleted";
            $statistics[$key] = ($statistics[$key] ?? 0) + $deleted;
        }

        return $statistics;
    }

    /**
     * Handle retention policy for multiple log types within a tenant (batched for performance).
     *
     * Implements calendar year retention per BewachV §21 Abs. 4:
     * - Created 2022-03-15 + 3 years → delete from 2026-01-01
     * - Keep until end of Nth following calendar year
     *
     * @param  int  $tenantId  The tenant_id to process (ISOLATION)
     * @param  array<string>  $logNames  Array of log_names to process (all with same retention)
     * @param  int  $retentionYears  Number of years to retain (3, 8, or 10)
     * @param  array<string, int>  $statistics  Statistics array (passed by reference)
     * @return int Number of logs deleted
     */
    protected function handleRetentionForLogTypes(int $tenantId, array $logNames, int $retentionYears, array &$statistics): int
    {
        // Calculate cutoff date: created_at + N years, end of calendar year, +1 day, midnight
        // Example: 2022-03-15 + 3y = 2025-03-15 → endOfYear() = 2025-12-31 → +1d = 2026-01-01 00:00:00
        $cutoffDate = now()->subYears($retentionYears)->endOfYear()->addDay()->startOfDay();

        // Use whereIn for batch processing (Performance: 1 query vs N queries)
        $query = Activity::where('tenant_id', $tenantId)
            ->whereIn('log_name', $logNames)
            ->where('created_at', '<', $cutoffDate)
            ->whereNull('deleted_at');

        $count = (int) $query->count();

        if ($this->option('dry-run')) {
            $logNamesStr = implode(', ', array_map(fn ($n) => "'{$n}'", $logNames));
            $this->line("Would delete {$count} logs ({$logNamesStr}) older than {$cutoffDate->format('Y-m-d')}");

            return $count;
        }

        $orphaned = 0;

        // Batch orphaned genesis lookup to avoid N+1 queries
        // Collect all event_hashes first, then do single lookup
        $logsToDelete = $query->get();
        $eventHashes = $logsToDelete->pluck('event_hash')->toArray();

        if (! empty($eventHashes)) {
            // Single query to find all logs that will become orphaned
            $logsToOrphan = Activity::where('tenant_id', $tenantId)
                ->whereIn('previous_hash', $eventHashes)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('previous_hash');

            foreach ($logsToDelete as $log) {
                // Check if this log has a successor that will become orphaned
                if (isset($logsToOrphan[$log->event_hash])) {
                    $nextLog = $logsToOrphan[$log->event_hash];
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
        }

        $statistics['orphaned_created'] += $orphaned;

        $logNamesStr = implode(', ', array_map(fn ($n) => "'{$n}'", $logNames));
        $this->info("✓ Deleted {$count} logs ({$logNamesStr}) older than {$cutoffDate->format('Y-m-d')}");
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
