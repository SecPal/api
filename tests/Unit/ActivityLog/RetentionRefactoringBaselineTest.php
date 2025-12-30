<?php

declare(strict_types=1);

use App\Models\Activity;

/**
 * Baseline tests to capture CURRENT behavior before refactoring.
 *
 * These tests document how the system works NOW (with "retention years")
 * after completing the refactoring from security levels.
 *
 * Pure unit tests - no database required.
 *
 * @see https://github.com/SecPal/api/issues/441
 */

/**
 * BASELINE: Unknown log types default to 3 years retention.
 */
test('baseline unknown log types default to 3 years', function (): void {
    expect(Activity::getRetentionYears('unknown_log_type'))->toBe(3);
    expect(Activity::getRetentionYears(''))->toBe(3);
});

/**
 * BASELINE: Current retention mapping based on legal requirements.
 *
 * This documents the ACTUAL legal retention periods:
 * - 3 years: BewachV §21 Abs. 4 (Bewachungsgewerbe)
 * - 8 years: HGB §257 Abs. 4 (Buchungsbelege)
 * - 10 years: HGB §257 Abs. 1 Nr. 1 (Jahresabschlüsse)
 */
test('baseline retention periods based on legal requirements', function (): void {
    // BewachV §21 Abs. 4: 3 years
    expect(Activity::getRetentionYears('shift_management'))->toBe(3);
    expect(Activity::getRetentionYears('security'))->toBe(3);
    expect(Activity::getRetentionYears('guard_book_event'))->toBe(3);

    // HGB §257 Buchungsbelege: 8 years
    expect(Activity::getRetentionYears('invoice_generated'))->toBe(8);
    expect(Activity::getRetentionYears('contract_change'))->toBe(8);

    // HGB §257 Jahresabschlüsse: 10 years
    expect(Activity::getRetentionYears('annual_closing'))->toBe(10);
});
