<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Report persistence failure without retaining its sensitive causes or bindings. */
final class EmploymentTermsWriteException extends RuntimeException
{
    public readonly ?string $sqlState;

    public function __construct(?string $sqlState = null)
    {
        $this->sqlState = $sqlState !== null && preg_match('/^[A-Z0-9]{5}$/D', $sqlState) === 1
            ? $sqlState
            : null;

        parent::__construct($this->sqlState === null
            ? 'Employment terms could not be saved.'
            : "Employment terms could not be saved (SQLSTATE {$this->sqlState}).");
    }
}
