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
 * Three-tier retention strategy:
 *
 * Level 1 (Basic - 1 year retention):
 * - Log types: default, employee_changes, shift_management
 * - Soft delete after 1 year
 * - Hard delete after 2 years total
 * - Mark next log as orphaned genesis when predecessor deleted
 *
 * Level 2 (Enhanced - 3 years retention):
 * - Log types: security, authentication, rbac_changes
 * - Archive (hash only) after 3 years
 * - Delete archive after 5 years total
 * - Preserves hash chain integrity via archive table
 *
 * Level 3 (Forensic - 7 years retention):
 * - Log types: hr_access, guard_book, contracts
 * - NO automatic deletion (permanent retention)
 * - Full data + hash chain + merkle tree + OpenTimestamp
 *
 * BewachV §21 Abs. 4 Compliance:
 * Retention calculated to end of Nth following calendar year.
 * Example: Event 15.03.2023 with 3-year retention → keep until 31.12.2026
 *
 * GDPR Article 5(1)(e) - Storage Limitation:
 * Personal data deleted, cryptographic hashes retained for verification.
 *
 * @see ADR-010 Section 5: Retention & Archiving
 * @see Issue #392 PR-8: Create ActivityArchive model & retention commands
 */
class ApplyRetentionPolicies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activity:apply-retention
                            {--dry-run : Preview actions without executing}
                            {--level=* : Apply only specific level(s): 1, 2, or 3}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply retention policies to activity logs (3-tier strategy per ADR-010)';

    /**
     * Security level configuration mapping log names to retention levels.
     *
     * @var array<string, int>
     */
    protected array $logLevelMapping = [
        // Level 1: Basic (1 year retention)
        'default' => 1,
        'employee_changes' => 1,
        'shift_management' => 1,

        // Level 2: Enhanced (3 years retention)
        'security' => 2,
        'authentication' => 2,
        'rbac_changes' => 2,

        // Level 3: Forensic (7 years retention - permanent)
        'hr_access' => 3,
        'guard_book' => 3,
        'contracts' => 3,
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting retention policy application...');
        $this->info('Dry run: '.($this->option('dry-run') ? 'YES' : 'NO'));

        $levels = $this->option('level') ?: [1, 2, 3];
        $levels = array_map('intval', (array) $levels);

        $statistics = [
            'level1_soft_deleted' => 0,
            'level1_hard_deleted' => 0,
            'level1_orphaned_created' => 0,
            'level2_archived' => 0,
            'level2_archives_deleted' => 0,
            'level3_skipped' => 0,
        ];

        try {
            if (in_array(1, $levels, true)) {
                $this->info('');
                $this->info('=== Level 1: Basic Retention (1 year) ===');
                $statistics['level1_soft_deleted'] = $this->handleLevel1SoftDelete();
                $statistics['level1_hard_deleted'] = $this->handleLevel1HardDelete($statistics);
            }

            if (in_array(2, $levels, true)) {
                $this->info('');
                $this->info('=== Level 2: Enhanced Retention (3 years) ===');
                $statistics['level2_archived'] = $this->handleLevel2Archiving();
                $statistics['level2_archives_deleted'] = $this->handleLevel2ArchiveDeletion();
            }

            if (in_array(3, $levels, true)) {
                $this->info('');
                $this->info('=== Level 3: Forensic Retention (Permanent) ===');
                $statistics['level3_skipped'] = $this->handleLevel3Permanent();
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
     * Level 1: Soft delete activities older than 1 year.
     */
    protected function handleLevel1SoftDelete(): int
    {
        $level1LogNames = array_keys(array_filter($this->logLevelMapping, fn ($level) => $level === 1));

        $cutoffDate = now()->subYear()->endOfYear()->addDay(); // End of previous year + 1 day

        $query = Activity::whereIn('log_name', $level1LogNames)
            ->where('created_at', '<', $cutoffDate)
            ->whereNull('deleted_at');

        $count = (int) $query->count();

        if ($this->option('dry-run')) {
            $this->line("Would soft delete {$count} Level 1 logs older than {$cutoffDate->format('Y-m-d')}");

            return $count;
        }

        $query->each(function (Activity $log) {
            $log->delete(); // Soft delete
        });

        $this->info("✓ Soft deleted {$count} Level 1 logs older than {$cutoffDate->format('Y-m-d')}");

        return $count;
    }

    /**
     * Level 1: Hard delete soft-deleted activities older than 2 years total.
     * Mark next log as orphaned genesis when predecessor is deleted.
     *
     * @param  array<string, int>  $statistics
     */
    protected function handleLevel1HardDelete(array &$statistics): int
    {
        $level1LogNames = array_keys(array_filter($this->logLevelMapping, fn ($level) => $level === 1));

        $cutoffDate = now()->subYears(2)->endOfYear()->addDay();

        $query = Activity::onlyTrashed()
            ->whereIn('log_name', $level1LogNames)
            ->where('deleted_at', '<', $cutoffDate);

        $count = (int) $query->count();

        if ($this->option('dry-run')) {
            $this->line("Would hard delete {$count} soft-deleted Level 1 logs older than {$cutoffDate->format('Y-m-d')}");

            return $count;
        }

        $query->each(function (Activity $log) use (&$statistics): void {
            // Mark next log as orphaned genesis if it exists
            $nextLog = Activity::where('previous_hash', $log->event_hash)
                ->whereNull('deleted_at')
                ->first();

            if ($nextLog !== null) {
                $nextLog->update([
                    'previous_hash' => null,
                    'is_orphaned_genesis' => true,
                    'orphaned_reason' => 'Predecessor deleted (Level 1 retention policy)',
                    'orphaned_at' => now(),
                ]);
                $statistics['level1_orphaned_created']++;
            }

            $log->forceDelete();
        });

        $orphanedCount = $statistics['level1_orphaned_created'];
        $this->info("✓ Hard deleted {$count} soft-deleted Level 1 logs");
        $this->info("✓ Created {$orphanedCount} orphaned genesis markers");

        return $count;
    }

    /**
     * Level 2: Archive activities older than 3 years (hash only, no personal data).
     */
    protected function handleLevel2Archiving(): int
    {
        $level2LogNames = array_keys(array_filter($this->logLevelMapping, fn ($level) => $level === 2));

        $cutoffDate = now()->subYears(3)->endOfYear()->addDay();

        $query = Activity::whereIn('log_name', $level2LogNames)
            ->where('created_at', '<', $cutoffDate)
            ->whereNull('deleted_at');

        $count = (int) $query->count();

        if ($this->option('dry-run')) {
            $this->line("Would archive {$count} Level 2 logs older than {$cutoffDate->format('Y-m-d')}");

            return $count;
        }

        $query->each(function (Activity $log) {
            // Create archive entry (hash only, no personal data)
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

            // Hard delete original log (personal data removed)
            $log->forceDelete();
        });

        $this->info("✓ Archived {$count} Level 2 logs (personal data deleted, hashes retained)");

        return $count;
    }

    /**
     * Level 2: Delete archived logs older than 5 years total.
     */
    protected function handleLevel2ArchiveDeletion(): int
    {
        $cutoffDate = now()->subYears(5)->endOfYear()->addDay();

        $query = ActivityArchive::where('created_at', '<', $cutoffDate);

        $count = (int) $query->count();

        if ($this->option('dry-run')) {
            $this->line("Would delete {$count} archived logs older than {$cutoffDate->format('Y-m-d')}");

            return $count;
        }

        $deletedCount = $query->delete();
        assert(is_int($deletedCount));

        $this->info("✓ Deleted {$deletedCount} archived logs older than {$cutoffDate->format('Y-m-d')}");

        return $deletedCount;
    }

    /**
     * Level 3: Count permanent retention logs (no deletion).
     */
    protected function handleLevel3Permanent(): int
    {
        $level3LogNames = array_keys(array_filter($this->logLevelMapping, fn ($level) => $level === 3));

        $count = Activity::whereIn('log_name', $level3LogNames)
            ->whereNull('deleted_at')
            ->count();

        $this->info("✓ {$count} Level 3 logs retained permanently (no automatic deletion)");

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
                ['Level 1: Soft deleted', $statistics['level1_soft_deleted']],
                ['Level 1: Hard deleted', $statistics['level1_hard_deleted']],
                ['Level 1: Orphaned genesis created', $statistics['level1_orphaned_created']],
                ['Level 2: Archived', $statistics['level2_archived']],
                ['Level 2: Archives deleted', $statistics['level2_archives_deleted']],
                ['Level 3: Permanent (skipped)', $statistics['level3_skipped']],
                ['Total actions', array_sum($statistics)],
            ]
        );
    }
}
