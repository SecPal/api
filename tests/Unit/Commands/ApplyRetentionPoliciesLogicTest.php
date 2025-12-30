<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Models\Activity;
use Carbon\Carbon;

/**
 * TDD Tests for ApplyRetentionPolicies refactoring.
 *
 * After refactoring to retention-based model:
 * - Single handler for all retention periods (not 3 separate handlers)
 * - Calendar year boundaries (BewachV §21 Abs. 4)
 * - Use getRetentionYears() instead of levels
 *
 * Written BEFORE implementation (Test-Driven Development).
 *
 * @see https://github.com/SecPal/api/issues/441
 */
class ApplyRetentionPoliciesLogicTest extends \PHPUnit\Framework\TestCase
{
    /**
     * TDD: Calendar year calculation for retention cutoff.
     *
     * BewachV §21 Abs. 4: Retention until end of Nth following calendar year.
     *
     * Expected: Document correct calculation
     */
    public function test_calendar_year_retention_calculation(): void
    {
        // Example: Event 15.03.2022, 3 years retention
        // Created: 2022-03-15
        // N = 3 (retention years)
        // Delete from: 2022 + 3 + 1 = 2026-01-01 (end of 2025 + 1 day)

        $createdAt = Carbon::parse('2022-03-15 10:00:00');
        $retentionYears = 3;

        // Calculate cutoff: End of (created_year + retention_years) + 1 day at midnight
        $cutoffDate = $createdAt
            ->copy()
            ->addYears($retentionYears)
            ->endOfYear()
            ->addDay()       // First day AFTER retention period
            ->startOfDay();  // Midnight on that day

        // Should be 2026-01-01
        $this->assertSame('2026-01-01', $cutoffDate->format('Y-m-d'));

        // Log created 2022-03-15 with 3y retention:
        // - Keep through: 2025-12-31
        // - Delete from: 2026-01-01
    }

    /**
     * TDD: 8-year retention calculation.
     *
     * Expected: Document correct calculation
     */
    public function test_eight_year_retention_calculation(): void
    {
        // Invoice generated 2020-06-10, 8 years retention
        // Delete from: 2020 + 8 + 1 = 2029-01-01

        $createdAt = Carbon::parse('2020-06-10 14:30:00');
        $retentionYears = 8;

        $cutoffDate = $createdAt
            ->copy()
            ->addYears($retentionYears)
            ->endOfYear()
            ->addDay()
            ->startOfDay();

        $this->assertSame('2029-01-01', $cutoffDate->format('Y-m-d'));
    }

    /**
     * TDD: 10-year retention calculation.
     *
     * Expected: Document correct calculation
     */
    public function test_ten_year_retention_calculation(): void
    {
        // Annual closing 2019-12-31, 10 years retention
        // Delete from: 2019 + 10 + 1 = 2030-01-01

        $createdAt = Carbon::parse('2019-12-31 23:59:59');
        $retentionYears = 10;

        $cutoffDate = $createdAt
            ->copy()
            ->addYears($retentionYears)
            ->endOfYear()
            ->addDay()
            ->startOfDay();

        $this->assertSame('2030-01-01', $cutoffDate->format('Y-m-d'));
    }

    /**
     * TDD: Edge case - created on December 31st.
     *
     * Expected: Document correct calculation
     */
    public function test_created_on_december_31st(): void
    {
        // Created 2023-12-31, 3 years retention
        // Delete from: 2023 + 3 + 1 = 2027-01-01

        $createdAt = Carbon::parse('2023-12-31 23:59:59');
        $retentionYears = 3;

        $cutoffDate = $createdAt
            ->copy()
            ->addYears($retentionYears)
            ->endOfYear()
            ->addDay()
            ->startOfDay();

        $this->assertSame('2027-01-01', $cutoffDate->format('Y-m-d'));
    }

    /**
     * TDD: Edge case - created on January 1st.
     *
     * Expected: Document correct calculation
     */
    public function test_created_on_january_1st(): void
    {
        // Created 2024-01-01, 3 years retention
        // Delete from: 2024 + 3 + 1 = 2028-01-01

        $createdAt = Carbon::parse('2024-01-01 00:00:00');
        $retentionYears = 3;

        $cutoffDate = $createdAt
            ->copy()
            ->addYears($retentionYears)
            ->endOfYear()
            ->addDay()
            ->startOfDay();

        $this->assertSame('2028-01-01', $cutoffDate->format('Y-m-d'));
    }

    /**
     * TDD: All retention years should be processed.
     *
     * Expected: Pass (validates refactoring approach)
     */
    public function test_all_retention_periods_are_defined(): void
    {
        $retentionYears = Activity::getRetentionYears();

        // Should have 3, 8, and 10 year retention periods
        $uniqueYears = array_values(array_unique(array_values($retentionYears)));
        sort($uniqueYears);

        $this->assertContains(3, $uniqueYears, 'Should have 3-year retention');
        $this->assertContains(8, $uniqueYears, 'Should have 8-year retention');
        $this->assertContains(10, $uniqueYears, 'Should have 10-year retention');
    }

    /**
     * TDD: Verify retention mapping matches legal requirements.
     *
     * Expected: Pass
     */
    public function test_retention_mapping_matches_legal_requirements(): void
    {
        // BewachV §21 Abs. 4: 3 years
        $this->assertSame(3, Activity::getRetentionYears('shift_management'));
        $this->assertSame(3, Activity::getRetentionYears('guard_book'));

        // HGB §257: 8 years for Buchungsbelege
        $this->assertSame(8, Activity::getRetentionYears('invoice_generated'));
        $this->assertSame(8, Activity::getRetentionYears('contract_change'));

        // HGB §257: 10 years for Jahresabschlüsse
        $this->assertSame(10, Activity::getRetentionYears('annual_closing'));
    }

    /**
     * TDD: Default retention is 3 years (safest option).
     *
     * Expected: Pass
     */
    public function test_default_retention_is_three_years(): void
    {
        $defaultRetention = Activity::getRetentionYears('unknown_log_type');

        $this->assertSame(3, $defaultRetention, 'Default should be 3 years (BewachV minimum)');
    }

    /**
     * TDD: Helper method to check if log is deletable.
     *
     * Documents the logic for determining if a log can be deleted.
     *
     * Expected: Document logic
     */
    public function test_deletion_logic_with_calendar_years(): void
    {
        // Given: Log created 2022-03-15, 3 years retention
        $createdAt = Carbon::parse('2022-03-15 10:00:00');
        $retentionYears = 3;

        // Calculation: 2022-03-15 + 3y = 2025-03-15, endOfYear = 2025-12-31 23:59:59, +1d = 2026-01-01 23:59:59, startOfDay = 2026-01-01 00:00:00
        $cutoffDate = $createdAt->copy()->addYears($retentionYears)->endOfYear()->addDay()->startOfDay();

        // Expected: 2026-01-01 00:00:00
        $this->assertEquals('2026-01-01 00:00:00', $cutoffDate->format('Y-m-d H:i:s'));

        // Test dates: Compare created_at with cutoff
        $testCases = [
            ['2022-03-15 10:00:00', '2025-12-31 23:59:59', false, 'Should keep: still in retention'],
            ['2022-03-15 10:00:00', '2026-01-01 00:00:00', true, 'Should delete: cutoff reached'],
            ['2022-03-15 10:00:00', '2026-01-01 00:00:01', true, 'Should delete: after cutoff'],
            ['2022-03-15 10:00:00', '2027-06-15 12:00:00', true, 'Should delete: well after cutoff'],
        ];

        foreach ($testCases as [$createdAtStr, $nowStr, $shouldDelete, $message]) {
            $created = Carbon::parse($createdAtStr);
            $now = Carbon::parse($nowStr);

            // Calculate cutoff for THIS log
            $logCutoff = $created->copy()->addYears($retentionYears)->endOfYear()->addDay()->startOfDay();
            $isExpired = $now >= $logCutoff;

            $this->assertSame($shouldDelete, $isExpired, $message);
        }
    }

    /**
     * TDD: Verify command will iterate over all log types.
     *
     * Expected: Pass
     */
    public function test_command_should_process_all_log_types(): void
    {
        $allLogTypes = Activity::getRetentionYears();

        // Command should loop through ALL of these
        $this->assertIsArray($allLogTypes);
        $this->assertNotEmpty($allLogTypes);

        // Verify mix of retention periods
        $retention3 = array_filter($allLogTypes, fn ($years) => $years === 3);
        $retention8 = array_filter($allLogTypes, fn ($years) => $years === 8);
        $retention10 = array_filter($allLogTypes, fn ($years) => $years === 10);

        $this->assertNotEmpty($retention3, 'Should have 3-year logs');
        $this->assertNotEmpty($retention8, 'Should have 8-year logs');
        $this->assertNotEmpty($retention10, 'Should have 10-year logs');
    }
}
