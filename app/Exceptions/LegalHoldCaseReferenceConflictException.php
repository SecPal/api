<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use RuntimeException;

final class LegalHoldCaseReferenceConflictException extends RuntimeException
{
    public static function fromQueryException(QueryException $exception): ?self
    {
        if ((string) $exception->getCode() !== '23505'
            || ! str_contains($exception->getMessage(), 'legal_holds_tenant_case_reference_unique')) {
            return null;
        }

        return new self('A legal hold with this case reference already exists.', previous: $exception);
    }
}
