<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

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
        'Ä' => 'ae',
        'Ö' => 'oe',
        'Ü' => 'ue',
    ];

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return strtr($value, self::UMLAUT_MAP);
    }

    public static function normalizeAsciiFallback(string $value): string
    {
        return self::foldExpandedDigraphs(self::normalize($value));
    }

    public static function foldExpandedDigraphs(string $normalizedValue): string
    {
        return str_replace(['ae', 'oe', 'ue'], ['a', 'o', 'u'], $normalizedValue);
    }
}
