<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2025 SecPal <https://github.com/SecPal>
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Activity;

/**
 * TDD Tests for Activity retention years methods.
 *
 * Tests the NEW retention-based approach (replacement for security levels).
 *
 * Retention periods based on German legal requirements:
 * - 3 years: BewachV §21 Abs. 4 (Bewachungsverordnung)
 * - 8 years: HGB §257 Abs. 1 Nr. 4 (Handelsgesetzbuch - Buchungsbelege)
 * - 10 years: HGB §257 Abs. 1 Nr. 1 (Handelsgesetzbuch - Jahresabschlüsse)
 *
 * Written BEFORE implementation (Test-Driven Development).
 *
 * @see https://github.com/SecPal/api/issues/441
 */
test('returns 3 years for operational logs', function (): void {
    expect(Activity::getRetentionYearsForLogType('shift_management'))->toBe(3);
    expect(Activity::getRetentionYearsForLogType('employee_changes'))->toBe(3);
    expect(Activity::getRetentionYearsForLogType('security'))->toBe(3);
    expect(Activity::getRetentionYearsForLogType('authentication'))->toBe(3);
    expect(Activity::getRetentionYearsForLogType('guard_book_event'))->toBe(3);
});

test('returns 8 years for financial logs', function (): void {
    expect(Activity::getRetentionYearsForLogType('invoice_generated'))->toBe(8);
    expect(Activity::getRetentionYearsForLogType('payment_processed'))->toBe(8);
    expect(Activity::getRetentionYearsForLogType('contract_change'))->toBe(8);
});

test('returns 10 years for archival logs', function (): void {
    expect(Activity::getRetentionYearsForLogType('annual_closing'))->toBe(10);
});

test('returns default 3 years for unknown log types', function (): void {
    expect(Activity::getRetentionYearsForLogType('unknown_log_type'))->toBe(3);
    expect(Activity::getRetentionYearsForLogType('some_random_name'))->toBe(3);
});

test('default key returns 3 years', function (): void {
    expect(Activity::getRetentionYearsForLogType('default'))->toBe(3);
});

test('all retention years are valid integers', function (): void {
    $retentionYears = Activity::getAllRetentionYears();

    foreach ($retentionYears as $logName => $years) {
        expect($logName)->toBeString();
        expect($years)->toBeInt()
            ->toBeIn([3, 8, 10], "Retention years must be 3, 8, or 10 for {$logName}");
    }
});

test('retention years property has legal references', function (): void {
    // This test verifies the docblock contains legal references
    $reflection = new \ReflectionClass(Activity::class);
    $property = $reflection->getProperty('retentionYears');
    $docComment = $property->getDocComment();

    expect($docComment)->toContain('BewachV')
        ->toContain('HGB')
        ->toContain('§');
});

test('get retention years methods exist and are static', function (): void {
    expect(method_exists(Activity::class, 'getRetentionYearsForLogType'))->toBeTrue();
    expect(method_exists(Activity::class, 'getAllRetentionYears'))->toBeTrue();

    $reflection = new \ReflectionMethod(Activity::class, 'getRetentionYearsForLogType');
    expect($reflection->isStatic())->toBeTrue();

    $reflection2 = new \ReflectionMethod(Activity::class, 'getAllRetentionYears');
    expect($reflection2->isStatic())->toBeTrue();
});

test('get retention years for log type accepts string parameter', function (): void {
    $reflection = new \ReflectionMethod(Activity::class, 'getRetentionYearsForLogType');
    $parameters = $reflection->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getName())->toBe('logName');
    expect($parameters[0]->allowsNull())->toBeFalse();
});

test('get all retention years returns array', function (): void {
    $result = Activity::getAllRetentionYears();

    expect($result)->toBeArray()
        ->toHaveKey('default')
        ->toHaveKey('shift_management')
        ->toHaveKey('security')
        ->toHaveKey('invoice_generated')
        ->toHaveKey('annual_closing');
});
