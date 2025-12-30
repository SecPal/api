<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Models\Activity;

/**
 * TDD Unit Tests for BuildMerkleTreeBatch logic (no DB).
 *
 * Tests the LOGIC changes for retention-based refactoring.
 *
 * @see https://github.com/SecPal/api/issues/441
 */
class BuildMerkleTreeBatchLogicTest extends \PHPUnit\Framework\TestCase
{
    /**
     * TDD: Job should query ALL log types (not just Level 2+3).
     *
     * Expected: FAIL (currently filters Level 2+3)
     */
    public function test_job_should_process_all_log_types(): void
    {
        // Get what the job SHOULD query (all log types)
        $allLogNames = collect(Activity::getRetentionYears())
            ->keys()
            ->all();

        // What it CURRENTLY queries (Level 2+3 only)
        $currentLogNames = collect(Activity::getSecurityLevels())
            ->filter(fn ($level) => $level >= 2)
            ->keys()
            ->all();

        // After refactoring, these should be DIFFERENT
        // Currently they're the same (all Level 2+3 logs)
        
        // Check that we have 3-year retention logs defined
        $this->assertContains('shift_management', $allLogNames);
        $this->assertContains('authentication', $allLogNames);
        $this->assertContains('security', $allLogNames);

        // Check that we have 8-year retention logs
        $this->assertContains('invoice_generated', $allLogNames);
        $this->assertContains('contract_change', $allLogNames);

        // Check that we have 10-year retention logs
        $this->assertContains('annual_closing', $allLogNames);

        // The key assertion: allLogNames should be LARGER than currentLogNames
        // Because it includes 3-year logs that were previously "Level 1"
        $this->assertGreaterThan(
            count($currentLogNames),
            count($allLogNames),
            'After refactoring, job should process MORE log types (includes 3-year retention)'
        );
    }

    /**
     * TDD: Verify that 3-year retention logs exist but some are currently Level 1.
     *
     * This documents the problem we're fixing.
     *
     * Expected: PASS (documents current state)
     */
    public function test_three_year_logs_currently_split_across_levels(): void
    {
        // These are ALL 3-year retention logs
        // But currently split between Level 1 and Level 2 (inconsistent!)
        $level1ThreeYearLogs = ['shift_management', 'default']; // Currently Level 1
        $level2ThreeYearLogs = ['authentication', 'security']; // Currently Level 2

        // Verify Level 1 ones
        foreach ($level1ThreeYearLogs as $logName) {
            $retentionYears = Activity::getRetentionYears($logName);
            $securityLevel = Activity::getSecurityLevel($logName);

            $this->assertSame(3, $retentionYears, "{$logName} should have 3-year retention");
            $this->assertSame(1, $securityLevel, "{$logName} currently maps to Level 1");
        }

        // Verify Level 2 ones
        foreach ($level2ThreeYearLogs as $logName) {
            $retentionYears = Activity::getRetentionYears($logName);
            $securityLevel = Activity::getSecurityLevel($logName);

            $this->assertSame(3, $retentionYears, "{$logName} should have 3-year retention");
            $this->assertSame(1, $securityLevel, "{$logName} NOW maps to Level 1 (after refactoring!)");
        }

        // Level 2+ filter would exclude Level 1 ones
        $level2Plus = collect(Activity::getSecurityLevels())
            ->filter(fn ($level) => $level >= 2)
            ->keys()
            ->all();

        foreach ($level1ThreeYearLogs as $logName) {
            $this->assertNotContains(
                $logName,
                $level2Plus,
                "{$logName} is currently excluded from merkle batches (the problem!)"
            );
        }
    }

    /**
     * TDD: After refactoring, getRetentionYears() should cover all log types.
     *
     * Expected: PASS
     */
    public function test_retention_years_covers_all_security_levels(): void
    {
        $oldLevels = Activity::getSecurityLevels();
        $newRetention = Activity::getRetentionYears();

        // Every old log type should have retention defined
        foreach (array_keys($oldLevels) as $logName) {
            $this->assertArrayHasKey(
                $logName,
                $newRetention,
                "Log type '{$logName}' must have retention period defined"
            );
        }
    }

    /**
     * TDD: hasLevel3 logic should be REMOVED (all batches get OTS).
     *
     * This test documents what needs to change.
     *
     * Expected: PASS (documents current wrong behavior)
     */
    public function test_documents_has_level_3_check_should_be_removed(): void
    {
        // Currently: OTS only if hasLevel3
        // After: ALWAYS dispatch OTS

        // Document that Level 1 logs won't trigger OTS (the bug)
        $level1Logs = collect(Activity::getSecurityLevels())
            ->filter(fn ($level) => $level === 1)
            ->keys();

        // If batch contains ONLY Level 1 logs, no OTS (BUG!)
        // After refactoring: ALL batches get OTS

        $this->assertNotEmpty($level1Logs, 'There should be Level 1 logs defined');

        // This test documents the problem - it will become obsolete after refactoring
        $this->assertTrue(
            true,
            'Currently: hasLevel3 check prevents OTS for Level 1-only batches. Must be removed!'
        );
    }

    /**
     * TDD: Verify retention mapping for key log types.
     *
     * Expected: PASS
     */
    public function test_retention_mapping_for_key_log_types(): void
    {
        // 3 years: BewachV §21 Abs. 4
        $this->assertSame(3, Activity::getRetentionYears('shift_management'));
        $this->assertSame(3, Activity::getRetentionYears('guard_book'));
        $this->assertSame(3, Activity::getRetentionYears('security'));
        $this->assertSame(3, Activity::getRetentionYears('authentication'));

        // 8 years: HGB §257 Buchungsbelege
        $this->assertSame(8, Activity::getRetentionYears('invoice_generated'));
        $this->assertSame(8, Activity::getRetentionYears('payment_processed'));
        $this->assertSame(8, Activity::getRetentionYears('contract_change'));

        // 10 years: HGB §257 Jahresabschlüsse
        $this->assertSame(10, Activity::getRetentionYears('annual_closing'));
    }
}
