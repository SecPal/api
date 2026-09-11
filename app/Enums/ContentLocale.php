<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Enums;

enum ContentLocale: string
{
    case German = 'de';
    case English = 'en';

    public function fallback(): self
    {
        return match ($this) {
            self::German => self::English,
            self::English => self::German,
        };
    }
}
