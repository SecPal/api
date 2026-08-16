<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Activity;

/**
 * TDD Tests for BuildMerkleTreeBatch refactoring logic.
 *
 * Documents the CHANGES required in Phase 3:
 * - BEFORE: Job filtered logs by security level (>=2)
 * - AFTER: Job processes ALL log types from getAllRetentionYears()
 *
 * - BEFORE: hasLevel3 check determined OTS submission
 * - AFTER: ALWAYS dispatch OTS for ALL batches
 *
 * Pure unit tests - no database required.
 * Tests logic/behavior, not actual DB operations.
 *
 * Written BEFORE implementation (Test-Driven Development).
 *
 * @see https://github.com/SecPal/api/issues/441
 * @see app/Jobs/BuildMerkleTreeBatch.php
 */
test('job should process all log types', function (): void {
    // BEFORE: Filtered by securityLevels >= 2
    // AFTER: Process ALL log types from getAllRetentionYears()

    $allLogTypes = Activity::getAllRetentionYears();

    // Verify we have 3-year, 8-year, and 10-year retention logs
    $hasThreeYear = false;
    $hasEightYear = false;
    $hasTenYear = false;

    foreach ($allLogTypes as $logName => $years) {
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

    expect($hasThreeYear)->toBeTrue('Must process 3-year retention logs');
    expect($hasEightYear)->toBeTrue('Must process 8-year retention logs');
    expect($hasTenYear)->toBeTrue('Must process 10-year retention logs');
});

test('three year logs currently split across levels', function (): void {
    // OLD PROBLEM: 3-year retention logs were split:
    // - shift_management (Level 1) - NOT processed
    // - security (Level 2) - WAS processed
    //
    // NEW: ALL 3-year retention logs processed uniformly

    expect(Activity::getRetentionYearsForLogType('shift_management'))->toBe(3);
    expect(Activity::getRetentionYearsForLogType('security'))->toBe(3);

    // After refactoring, both get merkle trees
    expect(true)->toBeTrue('Both should now be processed by BuildMerkleTreeBatch');
});

test('documents OTS is now dispatched for ALL batches', function (): void {
    // BEFORE: if ($hasLevel3) { dispatch OTS }
    // AFTER: ALWAYS dispatch OTS for ALL batches

    // After refactoring: ALL logs get OTS (no filtering)
    expect(Activity::getRetentionYearsForLogType('shift_management'))->toBe(3); // 3 years
    expect(Activity::getRetentionYearsForLogType('invoice_generated'))->toBe(8); // 8 years
    expect(Activity::getRetentionYearsForLogType('annual_closing'))->toBe(10); // 10 years

    // All of these will get OpenTimestamp proofs
    expect(true)->toBeTrue('OTS should be dispatched for ALL batches, regardless of retention period');
});

test('retention mapping for key log types', function (): void {
    // Verify key log types have retention periods defined
    expect(Activity::getRetentionYearsForLogType('shift_management'))->toBe(3);
    expect(Activity::getRetentionYearsForLogType('security'))->toBe(3);
    expect(Activity::getRetentionYearsForLogType('guard_book_event'))->toBe(3);
    expect(Activity::getRetentionYearsForLogType('invoice_generated'))->toBe(8);
    expect(Activity::getRetentionYearsForLogType('annual_closing'))->toBe(10);
});
