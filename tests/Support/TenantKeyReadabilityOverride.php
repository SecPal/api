<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace Tests\Support;

use App\Models\TenantKey;

final class TenantKeyReadabilityOverride
{
    /** @var array<string, true> */
    private static array $unreadablePaths = [];

    public static function markUnreadable(string $path): void
    {
        self::$unreadablePaths[$path] = true;
        TenantKey::setReadableChecker(static function (string $p): bool {
            return ! isset(self::$unreadablePaths[$p]) && \is_readable($p);
        });
    }

    public static function clear(): void
    {
        self::$unreadablePaths = [];
        TenantKey::setReadableChecker(null);
    }
}
