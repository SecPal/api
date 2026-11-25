<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use SensitiveParameter;

final readonly class GlobalIdentityKeyReference
{
    public function __construct(
        #[SensitiveParameter] public string $id,
        public int $version,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $id) !== 1 || $version < 1) {
            throw new GlobalIdentityKeyException('Invalid global identity key reference.');
        }
    }
}
