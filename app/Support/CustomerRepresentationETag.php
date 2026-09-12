<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Support;

final class CustomerRepresentationETag
{
    /**
     * @param  array<string, mixed>  $responseBody
     */
    public static function strong(array $responseBody): string
    {
        $json = json_encode(
            $responseBody,
            JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return '"customer-edit-'.hash('sha256', $json).'"';
    }
}
