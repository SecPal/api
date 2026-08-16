<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use RuntimeException;

final class DuplicateResourceException extends RuntimeException
{
    /** @var list<string> */
    private const CONSTRAINTS = [
        'customers_tenant_legal_entity_vat_normalized_unique',
        'customers_tenant_legal_entity_name_address_normalized_unique',
        'unique_tenant_customer_number',
        'customer_establishments_tenant_customer_establishment_unique',
    ];

    public static function fromQueryException(QueryException $exception): ?self
    {
        if ((string) $exception->getCode() !== '23505') {
            return null;
        }

        foreach (self::CONSTRAINTS as $constraint) {
            if (str_contains($exception->getMessage(), $constraint)) {
                return new self('A matching record already exists.', previous: $exception);
            }
        }

        return null;
    }
}
