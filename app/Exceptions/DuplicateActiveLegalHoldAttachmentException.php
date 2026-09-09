<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use RuntimeException;

final class DuplicateActiveLegalHoldAttachmentException extends RuntimeException
{
    public static function fromQueryException(QueryException $exception): ?self
    {
        if ((string) $exception->getCode() !== '23505'
            || ! str_contains($exception->getMessage(), 'legal_hold_attachments_active_identity_unique')) {
            return null;
        }

        return new self('The activity is already attached to this legal hold.', previous: $exception);
    }
}
