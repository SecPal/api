<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

namespace App\Traits;

/**
 * Trait for normalizing Person fields before blind index generation.
 *
 * Ensures consistent normalization across PersonObserver and PersonRepository.
 */
trait NormalizesPersonFields
{
    /**
     * Normalize email for blind index generation.
     *
     * Removes whitespace and converts to lowercase.
     */
    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Normalize phone for blind index generation.
     *
     * Extracts only digits (removes spaces, dashes, parentheses).
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?: '';
    }
}
