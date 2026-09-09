<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

final class LegalHoldNestedTransactionException extends LogicException
{
    public function __construct()
    {
        parent::__construct(
            'Legal Hold lifecycle mutations must own their top-level transaction.',
        );
    }
}
