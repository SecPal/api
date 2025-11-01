<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Support;

/**
 * Blind index generator using HMAC-SHA256.
 *
 * Provides normalized hashing for searchable encrypted fields without
 * exposing plaintext or allowing LIKE queries.
 *
 * Normalization rules (verbindlich):
 * - Email: lowercase + trim whitespace
 * - Phone/Badge: digits only
 */
class BlindIndex
{
    /**
     * Normalize email: lowercase, trim whitespace.
     *
     * Ensures "Test@Example.COM" and "test@example.com" produce same index.
     */
    public static function normEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /**
     * Normalize phone: strip all non-digits.
     *
     * Ensures "+49 (123) 456-789" and "49123456789" produce same index.
     */
    public static function normPhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone);
    }

    /**
     * Normalize badge/ID: digits only.
     *
     * Same as phone normalization (strip all non-digits).
     */
    public static function normBadge(string $badge): string
    {
        return preg_replace('/\D+/', '', $badge);
    }

    /**
     * Generate HMAC-SHA256 blind index (binary, 32 bytes).
     *
     * @param  string  $normalizedValue  Normalized plaintext value
     * @param  string  $idxKey  Index key (32 bytes, tenant-specific)
     * @return string Binary HMAC (32 bytes, BYTEA in PostgreSQL)
     */
    public static function hmac(string $normalizedValue, string $idxKey): string
    {
        if (strlen($idxKey) < 32) {
            throw new \InvalidArgumentException('Index key must be at least 32 bytes');
        }

        return hash_hmac('sha256', $normalizedValue, $idxKey, true);
    }

    /**
     * Verify that an index matches a value (constant-time comparison).
     *
     * @param  string  $value  Plaintext value to verify
     * @param  string  $storedIdx  Stored blind index (binary)
     * @param  string  $idxKey  Index key (32 bytes)
     * @param  callable  $normalizer  Normalization function (e.g., 'BlindIndex::normEmail')
     * @return bool True if matches
     */
    public static function verify(string $value, string $storedIdx, string $idxKey, callable $normalizer): bool
    {
        $normalized = $normalizer($value);
        $computedIdx = self::hmac($normalized, $idxKey);

        return hash_equals($storedIdx, $computedIdx);
    }
}
