<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class LegalHoldAttachmentAlreadyDetachedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The legal hold attachment is already detached.');
    }
}
