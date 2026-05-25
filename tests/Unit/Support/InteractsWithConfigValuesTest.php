<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Support\Concerns\InteractsWithConfigValues;

function interactsWithConfigValuesHarness(): object
{
    return new class
    {
        use InteractsWithConfigValues {
            positiveIntegerConfig as public readPositiveIntegerConfig;
        }
    };
}

test('positiveIntegerConfig returns positive integers from integer and numeric string config values', function (): void {
    $harness = interactsWithConfigValuesHarness();

    config()->set('test.positive_integer.int', 7);
    config()->set('test.positive_integer.string', '11');

    expect($harness->readPositiveIntegerConfig('test.positive_integer.int'))->toBe(7)
        ->and($harness->readPositiveIntegerConfig('test.positive_integer.string'))->toBe(11);
});

test('positiveIntegerConfig returns null without a default for missing and invalid config values', function (): void {
    $harness = interactsWithConfigValuesHarness();

    config()->set('test.positive_integer.zero', 0);
    config()->set('test.positive_integer.zero_string', '0');
    config()->set('test.positive_integer.negative_int', -3);
    config()->set('test.positive_integer.negative_string', '-1');
    config()->set('test.positive_integer.invalid_string', 'abc');

    expect($harness->readPositiveIntegerConfig('test.positive_integer.missing'))->toBeNull()
        ->and($harness->readPositiveIntegerConfig('test.positive_integer.zero'))->toBeNull()
        ->and($harness->readPositiveIntegerConfig('test.positive_integer.zero_string'))->toBeNull()
        ->and($harness->readPositiveIntegerConfig('test.positive_integer.negative_int'))->toBeNull()
        ->and($harness->readPositiveIntegerConfig('test.positive_integer.negative_string'))->toBeNull()
        ->and($harness->readPositiveIntegerConfig('test.positive_integer.invalid_string'))->toBeNull();
});

test('positiveIntegerConfig returns the provided default for missing and invalid config values', function (): void {
    $harness = interactsWithConfigValuesHarness();

    config()->set('test.positive_integer.zero_with_default', 0);
    config()->set('test.positive_integer.zero_string_with_default', '0');
    config()->set('test.positive_integer.negative_int_with_default', -3);
    config()->set('test.positive_integer.invalid_with_default', 'abc');
    config()->set('test.positive_integer.valid_with_default', 7);

    expect($harness->readPositiveIntegerConfig('test.positive_integer.missing_with_default', 5))->toBe(5)
        ->and($harness->readPositiveIntegerConfig('test.positive_integer.zero_with_default', 5))->toBe(5)
        ->and($harness->readPositiveIntegerConfig('test.positive_integer.zero_string_with_default', 5))->toBe(5)
        ->and($harness->readPositiveIntegerConfig('test.positive_integer.negative_int_with_default', 5))->toBe(5)
        ->and($harness->readPositiveIntegerConfig('test.positive_integer.invalid_with_default', 5))->toBe(5)
        ->and($harness->readPositiveIntegerConfig('test.positive_integer.valid_with_default', 5))->toBe(7);
});
