<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Support\ApiTimestamp;
use Carbon\CarbonImmutable;

test('it serializes timestamps as UTC whole-second strings with a trailing Z', function (): void {
    $timestamp = CarbonImmutable::parse('2026-05-30T12:34:56.789+02:00');

    expect(ApiTimestamp::format($timestamp))->toBe('2026-05-30T10:34:56Z');
});

test('it keeps already-UTC timestamps unchanged except for stripping sub-seconds', function (): void {
    $timestamp = CarbonImmutable::parse('2026-05-30T10:34:56.123Z');

    expect(ApiTimestamp::format($timestamp))->toBe('2026-05-30T10:34:56Z');
});

test('it returns null for nullable timestamps without a value', function (): void {
    expect(ApiTimestamp::nullable(null))->toBeNull();
});

test('it serializes non-null nullable timestamps the same as format', function (): void {
    $timestamp = CarbonImmutable::parse('2026-05-30T08:00:00+00:00');

    expect(ApiTimestamp::nullable($timestamp))->toBe('2026-05-30T08:00:00Z');
});
