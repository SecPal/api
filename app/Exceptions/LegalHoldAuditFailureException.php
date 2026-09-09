<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\LegalHoldAuditOperation;
use App\Enums\LegalHoldAuditOutcome;
use RuntimeException;
use Throwable;

final class LegalHoldAuditFailureException extends RuntimeException
{
    private function __construct(
        public readonly LegalHoldAuditOperation $operation,
        public readonly LegalHoldAuditOutcome $outcome,
        public readonly Throwable $auditFailure,
        Throwable $previous,
    ) {
        parent::__construct('Required Legal Hold audit evidence could not be persisted.', previous: $previous);
    }

    public static function forSuccessfulMutation(
        LegalHoldAuditOperation $operation,
        Throwable $auditFailure,
    ): self {
        return new self(
            $operation,
            LegalHoldAuditOutcome::Succeeded,
            $auditFailure,
            $auditFailure,
        );
    }

    public static function afterFailedMutation(
        LegalHoldAuditOperation $operation,
        Throwable $mutationFailure,
        Throwable $auditFailure,
    ): self {
        return new self(
            $operation,
            LegalHoldAuditOutcome::Failed,
            $auditFailure,
            $mutationFailure,
        );
    }
}
