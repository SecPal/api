<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class BewacherregisterExportNotReadyException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(implode(', ', $this->translatedErrors()));
    }

    /**
     * @return list<string>
     */
    public function translatedErrors(): array
    {
        return array_map(
            static fn (string $error): string => __('bwr_export.missing_fields.'.$error),
            $this->errors,
        );
    }
}
