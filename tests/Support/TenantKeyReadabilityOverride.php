<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models {
    if (! function_exists(__NAMESPACE__.'\\is_readable')) {
        function is_readable(string $path): bool
        {
            return \Tests\Support\TenantKeyReadabilityOverride::isReadable($path);
        }
    }
}

namespace Tests\Support {
    final class TenantKeyReadabilityOverride
    {
        /** @var array<string, true> */
        private static array $unreadablePaths = [];

        public static function markUnreadable(string $path): void
        {
            self::$unreadablePaths[$path] = true;
        }

        public static function clear(): void
        {
            self::$unreadablePaths = [];
        }

        public static function isReadable(string $path): bool
        {
            if (isset(self::$unreadablePaths[$path])) {
                return false;
            }

            return \is_readable($path);
        }
    }
}
