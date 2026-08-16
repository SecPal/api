<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class PushMessageDataNormalizer
{
    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    public static function normalize(array $data, string $channel): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException(sprintf('%s message data keys must be non-empty strings.', $channel));
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException(sprintf('%s message data value for "%s" must be a string.', $channel, $key));
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
