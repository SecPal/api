<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use SensitiveParameter;

final class GlobalIdentityCanonicalEncoding
{
    public static function encode(#[SensitiveParameter] string ...$fields): string
    {
        $encoded = '';
        foreach ($fields as $field) {
            $encoded .= pack('N', strlen($field)).$field;
        }

        return $encoded;
    }
}
