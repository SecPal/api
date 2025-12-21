<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Tests\Unit\ActivityLog;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * Test Activity Log Retention Calculation according to BewachV § 21 Abs. 4.
 *
 * BewachV § 21 Abs. 4: "Die Aufzeichnungen und Belege sind bis zum Schluss
 * des dritten auf den Zeitpunkt ihrer Entstehung folgenden Kalenderjahres
 * in den Geschäftsräumen aufzubewahren."
 *
 * Translation: Records must be kept until the END of the third calendar year
 * following their creation.
 *
 * Example: Event created on 15 March 2023
 * → Must be kept until 31 December 2026 (end of 3rd FOLLOWING year after 2023)
 * → Deletion allowed from 1 January 2027
 * → That's 3 full following years + potential remainder of creation year
 *
 * This test validates the retention calculation for all three security levels.
 */
class ActivityLogRetentionTest extends TestCase
{
    /**
     * Test Level 1 (Basic) retention: 3 years according to BewachV § 21 Abs. 4.
     *
     * BewachV mandates minimum 3 years for basic security records.
     */
    public function test_level_1_retention_follows_bewachv_3_years(): void
    {
        // Level 1: Basic - 3 years retention (BewachV § 21 Abs. 4)
        $retentionYears = 3;

        // Test: Event created on 1 January 2023
        // → Must be kept until 31 December 2026 (end of 3rd following year)
        $createdAt = Carbon::parse('2023-01-01 10:00:00');
        $expectedDeletionDate = Carbon::parse('2027-01-01 00:00:00'); // First day AFTER retention period

        $actualDeletionDate = $this->calculateDeletionDate($createdAt, $retentionYears);

        expect($actualDeletionDate->toDateString())
            ->toBe($expectedDeletionDate->toDateString())
            ->and($createdAt->diffInDays($actualDeletionDate, false))
            ->toBeGreaterThanOrEqual(365 * $retentionYears) // At least 3 years
            ->and($createdAt->diffInDays($actualDeletionDate, false))
            ->toBeLessThan(365 * $retentionYears + 366); // Max 3 years + 365 days (leap year)
    }

    /**
     * Test Level 1 retention for event created at end of year.
     */
    public function test_level_1_retention_event_created_end_of_year(): void
    {
        $retentionYears = 3;

        // Test: Event created on 31 December 2023
        // → Must be kept until 31 December 2026 (end of 3rd following year)
        $createdAt = Carbon::parse('2023-12-31 23:59:59');
        $expectedDeletionDate = Carbon::parse('2027-01-01 00:00:00');

        $actualDeletionDate = $this->calculateDeletionDate($createdAt, $retentionYears);

        expect($actualDeletionDate->toDateString())
            ->toBe($expectedDeletionDate->toDateString())
            ->and($createdAt->diffInDays($actualDeletionDate, false))
            ->toBeGreaterThanOrEqual(365 * $retentionYears); // At least 3 years
    }

    /**
     * Test Level 1 retention for mid-year event.
     */
    public function test_level_1_retention_event_created_mid_year(): void
    {
        $retentionYears = 3;

        // Test: Event created on 15 June 2023
        // → Must be kept until 31 December 2026 (end of 3rd following year)
        $createdAt = Carbon::parse('2023-06-15 14:30:00');
        $expectedDeletionDate = Carbon::parse('2027-01-01 00:00:00');

        $actualDeletionDate = $this->calculateDeletionDate($createdAt, $retentionYears);

        expect($actualDeletionDate->toDateString())
            ->toBe($expectedDeletionDate->toDateString());
    }

    /**
     * Test Level 2 (Enhanced) retention: 5 years.
     */
    public function test_level_2_retention_5_years(): void
    {
        // Level 2: Enhanced - 5 years retention
        $retentionYears = 5;

        // Test: Event created on 1 January 2023
        // → Must be kept until 31 December 2028 (end of 5th following year)
        $createdAt = Carbon::parse('2023-01-01 10:00:00');
        $expectedDeletionDate = Carbon::parse('2029-01-01 00:00:00');

        $actualDeletionDate = $this->calculateDeletionDate($createdAt, $retentionYears);

        expect($actualDeletionDate->toDateString())
            ->toBe($expectedDeletionDate->toDateString())
            ->and($createdAt->diffInDays($actualDeletionDate, false))
            ->toBeGreaterThanOrEqual(365 * $retentionYears); // At least 5 years
    }

    /**
     * Test Level 2 retention for event created at end of year.
     */
    public function test_level_2_retention_event_created_end_of_year(): void
    {
        $retentionYears = 5;

        // Test: Event created on 31 December 2023
        // → Must be kept until 31 December 2028 (end of 5th following year)
        $createdAt = Carbon::parse('2023-12-31 23:59:59');
        $expectedDeletionDate = Carbon::parse('2029-01-01 00:00:00');

        $actualDeletionDate = $this->calculateDeletionDate($createdAt, $retentionYears);

        expect($actualDeletionDate->toDateString())
            ->toBe($expectedDeletionDate->toDateString());
    }

    /**
     * Test Level 3 (Forensic) retention: 7 years.
     */
    public function test_level_3_retention_7_years(): void
    {
        // Level 3: Forensic - 7 years retention (maximum security)
        $retentionYears = 7;

        // Test: Event created on 1 January 2023
        // → Must be kept until 31 December 2030 (end of 7th FOLLOWING year after 2023)
        // → Deletion allowed from 1 January 2031
        $createdAt = Carbon::parse('2023-01-01 10:00:00');
        $expectedDeletionDate = Carbon::parse('2031-01-01 00:00:00');

        $actualDeletionDate = $this->calculateDeletionDate($createdAt, $retentionYears);

        expect($actualDeletionDate->toDateString())
            ->toBe($expectedDeletionDate->toDateString())
            ->and($createdAt->diffInDays($actualDeletionDate, false))
            ->toBeGreaterThanOrEqual(365 * $retentionYears); // At least 7 years
    }

    /**
     * Test Level 3 retention for event created at end of year.
     */
    public function test_level_3_retention_event_created_end_of_year(): void
    {
        $retentionYears = 7;

        // Test: Event created on 31 December 2023
        // → Must be kept until 31 December 2030 (end of 7th following year)
        $createdAt = Carbon::parse('2023-12-31 23:59:59');
        $expectedDeletionDate = Carbon::parse('2031-01-01 00:00:00');

        $actualDeletionDate = $this->calculateDeletionDate($createdAt, $retentionYears);

        expect($actualDeletionDate->toDateString())
            ->toBe($expectedDeletionDate->toDateString());
    }

    /**
     * Test Level 3 retention for mid-year event.
     */
    public function test_level_3_retention_event_created_mid_year(): void
    {
        $retentionYears = 7;

        // Test: Event created on 15 March 2023
        // → Must be kept until 31 December 2030 (end of 7th FOLLOWING year after 2023)
        // → Deletion allowed from 1 January 2031
        $createdAt = Carbon::parse('2023-03-15 08:45:00');
        $expectedDeletionDate = Carbon::parse('2031-01-01 00:00:00');

        $actualDeletionDate = $this->calculateDeletionDate($createdAt, $retentionYears);

        expect($actualDeletionDate->toDateString())
            ->toBe($expectedDeletionDate->toDateString());
    }

    /**
     * Test retention calculation respects leap years.
     */
    public function test_retention_calculation_respects_leap_years(): void
    {
        $retentionYears = 3;

        // Test: Event created in leap year 2024
        // → Must be kept until 31 December 2027 (end of 3rd following year)
        $createdAt = Carbon::parse('2024-02-29 12:00:00'); // Leap day
        $expectedDeletionDate = Carbon::parse('2028-01-01 00:00:00');

        $actualDeletionDate = $this->calculateDeletionDate($createdAt, $retentionYears);

        expect($actualDeletionDate->toDateString())
            ->toBe($expectedDeletionDate->toDateString());
    }

    /**
     * Test multiple events in same year have same deletion date.
     */
    public function test_events_in_same_year_have_same_deletion_date(): void
    {
        $retentionYears = 3;

        $event1 = Carbon::parse('2023-01-01 00:00:00');
        $event2 = Carbon::parse('2023-06-15 12:00:00');
        $event3 = Carbon::parse('2023-12-31 23:59:59');

        $deletionDate1 = $this->calculateDeletionDate($event1, $retentionYears);
        $deletionDate2 = $this->calculateDeletionDate($event2, $retentionYears);
        $deletionDate3 = $this->calculateDeletionDate($event3, $retentionYears);

        // All events from 2023 must be kept until 31 December 2026
        expect($deletionDate1->toDateString())
            ->toBe($deletionDate2->toDateString())
            ->toBe($deletionDate3->toDateString())
            ->toBe('2027-01-01'); // First day AFTER retention period ends
    }

    /**
     * Test config values match BewachV requirements.
     */
    public function test_config_retention_periods_are_correct(): void
    {
        // Level 1: BewachV § 21 Abs. 4 mandates 3 years minimum
        $level1Config = config('activitylog.security_levels.basic.delete_records_older_than_years');
        expect($level1Config)->toBe(3);

        // Level 2: Enhanced security (5 years)
        $level2Config = config('activitylog.security_levels.enhanced.delete_records_older_than_years');
        expect($level2Config)->toBe(5);

        // Level 3: Forensic security (7 years maximum)
        $level3Config = config('activitylog.security_levels.forensic.delete_records_older_than_years');
        expect($level3Config)->toBe(7);
    }

    /**
     * Calculate deletion date according to BewachV § 21 Abs. 4.
     *
     * Records must be kept until the END of the Nth calendar year following their creation.
     *
     * @param  \Carbon\Carbon  $createdAt  Event creation date
     * @param  int  $retentionYears  Number of years to retain (3, 5, or 7)
     * @return \Carbon\Carbon Earliest deletion date (first day AFTER retention period)
     */
    private function calculateDeletionDate(Carbon $createdAt, int $retentionYears): Carbon
    {
        // Get the year of creation
        $creationYear = $createdAt->year;

        // Calculate the year when retention period ends
        // BewachV: "bis zum Schluss des Xten [...] FOLGENDEN Kalenderjahres"
        // Example: Event 2023, 7 years → kept until end of 2030 (7 years AFTER 2023)
        $retentionEndYear = $creationYear + $retentionYears;

        // Deletion is allowed on the first day AFTER the retention period ends
        // Retention ends on 31 December of the retention year
        // Deletion starts on 1 January of the following year
        return Carbon::create($retentionEndYear + 1, 1, 1, 0, 0, 0);
    }
}
