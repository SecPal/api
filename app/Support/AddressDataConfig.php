<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Support;

/**
 * Typed accessors for config('address_data.*') values (PHPStan-safe).
 */
final class AddressDataConfig
{
    public static function string(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) ? $value : $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = config($key);
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
