<?php

declare(strict_types=1);

use App\Models\Activity;

/**
 * Baseline tests to capture CURRENT behavior before refactoring.
 *
 * These tests document how the system works NOW (with "security levels")
 * so we can ensure backward compatibility during the refactoring.
 *
 * Pure unit tests - no database required.
 *
 * @see https://github.com/SecPal/api/issues/441
 */

/**
 * BASELINE: Document how security levels USED TO work (before refactoring).
 *
 * This test is now HISTORICAL - it documents the OLD behavior.
 * After refactoring, getSecurityLevel() maps retention years → levels.
 */
test('baseline OLD security level mapping', function (): void {
    // OLD behavior (from $securityLevels array):
    // Level 1: shift_management, default
    // Level 2: authentication, security, rbac_changes, etc.
    // Level 3: hr_access, guard_book_event, contract_change, etc.

    // NEW behavior (from $retentionYears):
    // All BewachV logs → 3 years → Level 1
    // Financial logs → 8 years → Level 2
    // Archival logs → 10 years → Level 3

    // The old array is still present for getSecurityLevels() backward compat
    $oldLevels = Activity::getSecurityLevels();

    expect($oldLevels)->toBeArray()
        ->toHaveKey('shift_management')
        ->toHaveKey('authentication')
        ->toHaveKey('guard_book_event');
});

/**
 * BASELINE: Unknown log types default to level 1.
 */
test('baseline unknown log types default to level 1', function (): void {
    expect(Activity::getSecurityLevel('unknown_log_type'))->toBe(1);
    expect(Activity::getSecurityLevel(''))->toBe(1);
});

/**
 * BASELINE: Current level → retention mapping (misleading!).
 *
 * This documents what users THINK the levels mean.
 * After refactoring, we'll replace with actual legal requirements.
 */
test('baseline current retention assumptions', function (): void {
    // Current ASSUMED retention (NOT legally correct!)
    // Level 1: ~1 year
    // Level 2: ~5 years
    // Level 3: ~7 years

    // After refactoring:
    // 3 years: BewachV §21 Abs. 4
    // 8 years: HGB §257 Buchungsbelege
    // 10 years: HGB §257 Jahresabschlüsse

    // This test documents that the current system has NO LEGAL BASIS
    expect(true)->toBeTrue('Current retention periods are arbitrary, not legally grounded');
});

/**
 * BASELINE: Verify that security levels array is defined.
 */
test('baseline security levels array exists', function (): void {
    $securityLevels = Activity::getSecurityLevels();

    expect($securityLevels)->toBeArray()
        ->toHaveKey('shift_management')
        ->toHaveKey('security')
        ->toHaveKey('guard_book_event');
});

/**
 * BASELINE: All defined log types have a security level.
 */
test('baseline all log types have security level', function (): void {
    $securityLevels = Activity::getSecurityLevels();

    foreach ($securityLevels as $logName => $level) {
        expect($logName)->toBeString();
        expect($level)->toBeInt()
            ->toBeGreaterThanOrEqual(1)
            ->toBeLessThanOrEqual(3);
    }
});

/**
 * BACKWARD COMPAT: After refactoring, getSecurityLevel() still works.
 *
 * This test ensures old code continues working via backward compatibility.
 */
test('backward compatibility get security level still works', function (): void {
    // After refactoring: getSecurityLevel() maps retention years → levels
    // 3 years → Level 1
    expect(Activity::getSecurityLevel('shift_management'))->toBe(1); // 3 years
    expect(Activity::getSecurityLevel('security'))->toBe(1); // 3 years

    // 8 years → Level 2
    expect(Activity::getSecurityLevel('invoice_generated'))->toBe(2); // 8 years

    // 10 years → Level 3
    expect(Activity::getSecurityLevel('annual_closing'))->toBe(3); // 10 years
});
