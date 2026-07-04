<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

final class ApiTimestamp
{
    private const UTC_SECONDS_FORMAT = 'Y-m-d\\TH:i:s\\Z';

    public static function format(DateTimeInterface $timestamp): string
    {
        return self::serialize($timestamp);
    }

    public static function nullable(?DateTimeInterface $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return self::serialize($timestamp);
    }

    private static function serialize(DateTimeInterface $timestamp): string
    {
        return Carbon::instance($timestamp)->utc()->format(self::UTC_SECONDS_FORMAT);
    }
}
