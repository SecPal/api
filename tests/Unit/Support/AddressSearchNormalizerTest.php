<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Support\AddressSearchNormalizer;

test('normalizes whitespace case and German umlauts', function (): void {
    expect(AddressSearchNormalizer::normalize('  Müllerstraße  '))->toBe('muellerstrasse');
    expect(AddressSearchNormalizer::normalize('Großer Platz'))->toBe('grosser platz');
});

test('ascii fallback only folds literal umlauts', function (): void {
    expect(AddressSearchNormalizer::normalizeAsciiFallback('Müllerstraße'))->toBe('mullerstrasse')
        ->and(AddressSearchNormalizer::normalizeAsciiFallback('Neue Straße'))->toBe('neue strasse')
        ->and(AddressSearchNormalizer::normalizeAsciiFallback('Raeuberweg'))->toBe('raeuberweg');
});
