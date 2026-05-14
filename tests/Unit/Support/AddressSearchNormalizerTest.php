<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Support\AddressSearchNormalizer;

test('normalizes whitespace case and German umlauts', function (): void {
    expect(AddressSearchNormalizer::normalize('  Müllerstraße  '))->toBe('muellerstrasse');
    expect(AddressSearchNormalizer::normalize('Großer Platz'))->toBe('grosser platz');
});
