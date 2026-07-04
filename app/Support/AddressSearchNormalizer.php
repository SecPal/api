<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Support;

/**
 * Normalizes free-text fields for consistent autocomplete matching (German-friendly).
 */
final class AddressSearchNormalizer
{
    /**
     * @var array<string, string>
     */
    private const UMLAUT_MAP = [
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'ß' => 'ss',
    ];

    /**
     * @var array<string, string>
     */
    private const ASCII_UMLAUT_MAP = [
        'ä' => 'a',
        'ö' => 'o',
        'ü' => 'u',
        'ß' => 'ss',
    ];

    public static function normalize(string $value): string
    {
        return strtr(self::normalizeBase($value), self::UMLAUT_MAP);
    }

    public static function normalizeAsciiFallback(string $value): string
    {
        return strtr(self::normalizeBase($value), self::ASCII_UMLAUT_MAP);
    }

    private static function normalizeBase(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }
}
