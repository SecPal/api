<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\ActivityArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
    protected $description = 'Apply calendar year retention policies: archive hashes + hard delete (GDPR Art. 17)';

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
            'retention_3_archived' => 0,
            'retention_8_archived' => 0,
            'retention_10_archived' => 0,
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
            'retention_3_archived' => 0,
            'retention_8_archived' => 0,
            'retention_10_archived' => 0,
            'orphaned_created' => 0,
        ];

        // Process all retention periods (3, 8, 10 years)
        $allRetentionYears = Activity::getAllRetentionYears();

        // Group log types by retention period to optimize queries
        // Before: N×M queries (100 tenants × 17 log types = 1700 queries)
        // After: N×3 queries (100 tenants × 3 retention periods = 300 queries)
        $logTypesByRetention = [];
        foreach ($allRetentionYears as $logName => $years) {
            $logTypesByRetention[$years][] = $logName;
        }

        foreach ($logTypesByRetention as $retentionYears => $logNames) {
            $archived = $this->handleRetentionForLogTypes($tenantId, $logNames, $retentionYears, $statistics);

            $key = "retention_{$retentionYears}_archived";
            $statistics[$key] = ($statistics[$key] ?? 0) + $archived;
        }

        return $statistics;
    }

    /**
     * Handle retention policy: Archive hashes + hard delete (GDPR Art. 17 compliant).
     *
     * Implements calendar year retention per BewachV §21 Abs. 4:
     * - Created 2022-03-15 + 3 years → delete from 2026-01-01
     * - Keep until end of Nth following calendar year
     *
     * GDPR Art. 17 "unverzüglich" compliance:
     * - Direct archive + hard delete (no soft delete grace period)
     * - Personal data immediately deleted
     * - Only cryptographic hashes retained in archive
     *
     * @param  int  $tenantId  The tenant_id to process (ISOLATION)
     * @param  array<string>  $logNames  Array of log_names to process (all with same retention)
     * @param  int  $retentionYears  Number of years to retain (3, 8, or 10)
     * @param  array<string, int>  $statistics  Statistics array (passed by reference)
     * @return int Number of logs archived and hard deleted
     */
    protected function handleRetentionForLogTypes(int $tenantId, array $logNames, int $retentionYears, array &$statistics): int
    {
        // Calculate cutoff date: created_at + N years, end of calendar year, +1 day, midnight
        // Example: 2022-03-15 + 3y = 2025-03-15 → endOfYear() = 2025-12-31 → +1d = 2026-01-01 00:00:00
        $cutoffDate = now()->subYears($retentionYears)->endOfYear()->addDay()->startOfDay();

        // Use whereIn for batch processing (Performance: 1 query vs N queries)
        // Batch process: fetch all logs, orphan successors, archive + hard delete
        $logsToArchive = Activity::where('tenant_id', $tenantId)
            ->whereIn('log_name', $logNames)
            ->where('created_at', '<', $cutoffDate)
            ->get();

        $count = $logsToArchive->count();

        if ($this->option('dry-run')) {
            $logNamesStr = implode(', ', array_map(fn ($n) => "'{$n}'", $logNames));
            $this->line("Would archive and hard delete {$count} logs ({$logNamesStr}) older than {$cutoffDate->format('Y-m-d')}");

            return $count;
        }

        if ($count === 0) {
            return 0;
        }

        $orphaned = 0;
        $eventHashes = $logsToArchive->pluck('event_hash')->toArray();

        // Single query to find all logs that will become orphaned
        if ($eventHashes === []) {
            $logsToOrphan = collect();
        } else {
            $logsToOrphan = Activity::where('tenant_id', $tenantId)
                ->whereIn('previous_hash', $eventHashes)
                ->get()
                ->keyBy('previous_hash');
        }

        // Use transaction for atomic archive + delete (all-or-nothing)
        // If any log fails to archive or delete, entire batch rolls back
        // This ensures GDPR compliance and audit integrity (no partial state)
        DB::transaction(function () use ($logsToArchive, $logsToOrphan, &$orphaned) {
            foreach ($logsToArchive as $log) {
                // Step 1: Archive hashes only (GDPR Art. 5(1)(e) - data minimization)
                ActivityArchive::create([
                    'id' => $log->id,
                    'tenant_id' => $log->tenant_id,
                    'log_name' => $log->log_name,
                    'created_at' => $log->created_at,
                    'event_hash' => $log->event_hash,
                    'previous_hash' => $log->previous_hash,
                    'merkle_root' => $log->merkle_root,
                    'merkle_batch_id' => $log->merkle_batch_id,
                ]);

                // Step 2: Mark successor as orphaned genesis (if exists)
                if (isset($logsToOrphan[$log->event_hash])) {
                    /** @var Activity $nextLog */
                    $nextLog = $logsToOrphan[$log->event_hash];
                    $nextLog->update([
                        'previous_hash' => null,
                        'is_orphaned_genesis' => true,
                        'orphaned_reason' => "Predecessor archived (retention: {$log->log_name}, {$log->created_at->format('Y-m-d')})",
                        'orphaned_at' => now(),
                    ]);
                    $orphaned++;
                }

                // Step 3: Hard delete (personal data removed per GDPR Art. 17)
                $log->forceDelete();
            }
        });

        $statistics['orphaned_created'] += $orphaned;

        $logNamesStr = implode(', ', array_map(fn ($n) => "'{$n}'", $logNames));
        $this->info("✓ Archived + deleted {$count} logs ({$logNamesStr}) older than {$cutoffDate->format('Y-m-d')}");
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
                ['3-year retention: Archived + Deleted', $statistics['retention_3_archived'] ?? 0],
                ['8-year retention: Archived + Deleted', $statistics['retention_8_archived'] ?? 0],
                ['10-year retention: Archived + Deleted', $statistics['retention_10_archived'] ?? 0],
                ['Orphaned genesis created', $statistics['orphaned_created']],
                ['Total processed', array_sum($statistics)],
            ]
        );
    }
}
