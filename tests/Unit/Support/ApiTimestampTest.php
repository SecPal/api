<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Support\ApiTimestamp;
use Carbon\CarbonImmutable;

test('it serializes timestamps as UTC whole-second strings with a trailing Z', function (): void {
    $timestamp = CarbonImmutable::parse('2026-05-30T12:34:56.789+02:00');

    expect(ApiTimestamp::format($timestamp))->toBe('2026-05-30T10:34:56Z');
});

test('it returns null for nullable timestamps without a value', function (): void {
    expect(ApiTimestamp::nullable(null))->toBeNull();
});
