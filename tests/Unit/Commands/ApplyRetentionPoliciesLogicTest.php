<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Activity;
use Carbon\Carbon;

/**
 * TDD Tests for ApplyRetentionPolicies command refactoring.
 *
 * Documents the calendar year retention calculation per BewachV §21 Abs. 4:
 * "Retention until end of Nth following calendar year"
 *
 * Formula: created_at + N years → endOfYear() → +1 day → startOfDay()
 * Example: 2022-03-15 + 3 years = keep until 2025-12-31, delete from 2026-01-01
 *
 * Replaces OLD 3-tier structure:
 * - handleLevel1SoftDelete() / handleLevel1HardDelete()
 * - handleLevel2Archiving() / handleLevel2ArchiveDeletion()
 * - handleLevel3Permanent()
 *
 * With NEW single handler:
 * - Use getAllRetentionYears() instead of levels
 *
 * Written BEFORE implementation (Test-Driven Development).
 *
 * @see https://github.com/SecPal/api/issues/441
 */

/**
 * TDD: Calendar year calculation for retention cutoff.
 *
 * BewachV §21 Abs. 4: Retention until end of Nth following calendar year.
 *
 * Expected: Document correct calculation
 */
test('calendar year retention calculation', function (): void {
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
    expect($cutoffDate->format('Y-m-d'))->toBe('2026-01-01');

    // Log created 2022-03-15 with 3y retention:
    // - Keep through: 2025-12-31
    // - Delete from: 2026-01-01
});

/**
 * TDD: 8-year retention calculation.
 *
 * Expected: Document correct calculation
 */
test('eight year retention calculation', function (): void {
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

    expect($cutoffDate->format('Y-m-d'))->toBe('2029-01-01');
});

/**
 * TDD: 10-year retention calculation.
 *
 * Expected: Document correct calculation
 */
test('ten year retention calculation', function (): void {
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

    expect($cutoffDate->format('Y-m-d'))->toBe('2030-01-01');
});

/**
 * TDD: Edge case - created on December 31st.
 *
 * Expected: Document correct calculation
 */
test('created on december 31st', function (): void {
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

    expect($cutoffDate->format('Y-m-d'))->toBe('2027-01-01');
});

/**
 * TDD: Edge case - created on January 1st.
 *
 * Expected: Document correct calculation
 */
test('created on january 1st', function (): void {
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

    expect($cutoffDate->format('Y-m-d'))->toBe('2028-01-01');
});

/**
 * TDD: Retention periods are defined (3, 8, 10 years).
 *
 * Expected: All retention periods defined
 */
test('all retention periods are defined', function (): void {
    $retentionYears = Activity::getAllRetentionYears();

    $hasThreeYear = false;
    $hasEightYear = false;
    $hasTenYear = false;

    foreach ($retentionYears as $years) {
        if ($years === 3) {
            $hasThreeYear = true;
        }
        if ($years === 8) {
            $hasEightYear = true;
        }
        if ($years === 10) {
            $hasTenYear = true;
        }
    }

    expect($hasThreeYear)->toBeTrue('Must have 3-year retention logs');
    expect($hasEightYear)->toBeTrue('Must have 8-year retention logs');
    expect($hasTenYear)->toBeTrue('Must have 10-year retention logs');
});

/**
 * TDD: Retention mapping matches legal requirements.
 *
 * Expected: Verify legal compliance
 */
test('retention mapping matches legal requirements', function (): void {
    // BewachV §21 Abs. 4: 3 years
    expect(Activity::getRetentionYearsForLogType('guard_book_event'))->toBe(3);

    // HGB §257 Abs. 1 Nr. 4: 8 years (Buchungsbelege)
    expect(Activity::getRetentionYearsForLogType('invoice_generated'))->toBe(8);

    // AO §147 Abs. 1 Nr. 1: 10 years (Jahresabschlüsse)
    expect(Activity::getRetentionYearsForLogType('annual_closing'))->toBe(10);
});

/**
 * TDD: Default retention is 3 years.
 *
 * Expected: Safe default for unknown log types
 */
test('default retention is three years', function (): void {
    expect(Activity::getRetentionYearsForLogType('unknown_log_type'))->toBe(3);
    expect(Activity::getRetentionYearsForLogType('default'))->toBe(3);
});

/**
 * TDD: Document deletion logic with calendar years.
 *
 * Documents the logic for determining if a log can be deleted.
 *
 * Expected: Document logic
 */
test('deletion logic with calendar years', function (): void {
    // Given: Log created 2022-03-15, 3 years retention
    $createdAt = Carbon::parse('2022-03-15 10:00:00');
    $retentionYears = 3;

    // Calculation: 2022-03-15 + 3y = 2025-03-15, endOfYear = 2025-12-31 23:59:59, +1d = 2026-01-01 23:59:59, startOfDay = 2026-01-01 00:00:00
    $cutoffDate = $createdAt->copy()->addYears($retentionYears)->endOfYear()->addDay()->startOfDay();

    // Expected: 2026-01-01 00:00:00
    expect($cutoffDate->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:00');

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

        expect($isExpired)->toBe($shouldDelete, $message);
    }
});

/**
 * TDD: Command should process all log types.
 *
 * Expected: Iterate over getAllRetentionYears()
 */
test('command should process all log types', function (): void {
    $retentionYears = Activity::getAllRetentionYears();

    expect($retentionYears)->toBeArray()
        ->not->toBeEmpty();

    // Verify we can iterate over all log types
    foreach ($retentionYears as $logName => $years) {
        expect($logName)->toBeString();
        expect($years)->toBeInt()->toBeIn([3, 8, 10]);
    }
});
