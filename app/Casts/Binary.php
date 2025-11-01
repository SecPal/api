<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast for binary data stored as base64 in VARCHAR columns.
 *
 * Strategy: Store binary data as base64-encoded strings in VARCHAR for
 * reliable cross-database support, avoiding PDO binary binding issues.
 *
 * - GET: Decode base64 from VARCHAR to raw binary
 * - SET: Encode raw binary to base64 for VARCHAR storage
 *
 * @implements CastsAttributes<string, string>
 */
class Binary implements CastsAttributes
{
    /**
     * Cast the given value from storage.
     *
     * VARCHAR columns store base64-encoded binary data for cross-DB reliability.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new \RuntimeException("Expected string for {$key}, got: ".gettype($value));
        }

        // Decode base64 from VARCHAR column
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw new \RuntimeException("Invalid base64 data in {$key}: expected base64-encoded binary");
        }

        return $decoded;
    }

    /**
     * Prepare the given value for storage.
     *
     * Encode binary to base64 for VARCHAR storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Encode to base64 for VARCHAR storage
        return base64_encode($value);
    }
}
