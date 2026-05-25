<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Support\Concerns;

trait InteractsWithConfigValues
{
    private function booleanConfig(string $key, bool $default): bool
    {
        $value = config($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $default;
    }

    private function trimmedStringConfig(string $key): ?string
    {
        $value = config($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function positiveIntegerConfig(string $key, ?int $default = null): ?int
    {
        $value = config($key);

        if (is_int($value)) {
            return $value > 0 ? $value : $default;
        }

        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/', $value)) {
            return $default;
        }

        return (int) $value;
    }
}
