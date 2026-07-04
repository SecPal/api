<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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

    private function httpUrlValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || preg_match('/\s/', $trimmed) === 1) {
            return null;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $components = parse_url($trimmed);

        if ($components === false
            || ! isset($components['scheme'], $components['host'])
            || isset($components['user'])
            || isset($components['pass'])) {
            return null;
        }

        $scheme = strtolower((string) $components['scheme']);
        $host = $components['host'];

        if (! in_array($scheme, ['http', 'https'], true)
            || ! is_string($host)
            || $host === ''
            || strtolower($host) === 'localhost') {
            return null;
        }

        return $trimmed;
    }
}
