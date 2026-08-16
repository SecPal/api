<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Support;

/**
 * KEK path counter helper class for parallel test isolation.
 * Uses static property instead of global variable for better encapsulation.
 */
class TestKekCounter
{
    private static int $counter = 0;

    public static function get(): int
    {
        return self::$counter;
    }

    public static function increment(): void
    {
        self::$counter++;
    }

    public static function reset(): void
    {
        self::$counter = 0;
    }
}
