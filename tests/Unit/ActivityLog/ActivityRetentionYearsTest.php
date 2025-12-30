<?php

declare(strict_types=1);

use App\Models\Activity;

/**
 * TDD Tests for Activity::getRetentionYears() method.
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
    expect(Activity::getRetentionYears('shift_management'))->toBe(3);
    expect(Activity::getRetentionYears('employee_changes'))->toBe(3);
    expect(Activity::getRetentionYears('security'))->toBe(3);
    expect(Activity::getRetentionYears('authentication'))->toBe(3);
    expect(Activity::getRetentionYears('guard_book_event'))->toBe(3);
});

test('returns 8 years for financial logs', function (): void {
    expect(Activity::getRetentionYears('invoice_generated'))->toBe(8);
    expect(Activity::getRetentionYears('payment_processed'))->toBe(8);
    expect(Activity::getRetentionYears('contract_change'))->toBe(8);
});

test('returns 10 years for archival logs', function (): void {
    expect(Activity::getRetentionYears('annual_closing'))->toBe(10);
});

test('returns default 3 years for unknown log types', function (): void {
    expect(Activity::getRetentionYears('unknown_log_type'))->toBe(3);
    expect(Activity::getRetentionYears('some_random_name'))->toBe(3);
});

test('default key returns 3 years', function (): void {
    expect(Activity::getRetentionYears('default'))->toBe(3);
});

test('all retention years are valid integers', function (): void {
    $retentionYears = Activity::getRetentionYears();

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

test('get retention years method exists and is static', function (): void {
    expect(method_exists(Activity::class, 'getRetentionYears'))->toBeTrue();

    $reflection = new \ReflectionMethod(Activity::class, 'getRetentionYears');
    expect($reflection->isStatic())->toBeTrue();
});

test('get retention years accepts string parameter', function (): void {
    $reflection = new \ReflectionMethod(Activity::class, 'getRetentionYears');
    $parameters = $reflection->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getName())->toBe('logName');
    expect($parameters[0]->allowsNull())->toBeTrue();
});

test('get retention years without parameter returns array', function (): void {
    $result = Activity::getRetentionYears();

    expect($result)->toBeArray()
        ->toHaveKey('default')
        ->toHaveKey('shift_management')
        ->toHaveKey('security')
        ->toHaveKey('invoice_generated')
        ->toHaveKey('annual_closing');
});
