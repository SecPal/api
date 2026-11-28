<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use SensitiveParameter;

final readonly class GlobalIdentityDeliveryResult
{
    public function __construct(
        public GlobalIdentityDeliveryOutcome $outcome,
        #[SensitiveParameter] public ?string $reference = null,
    ) {
        if ($reference !== null && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $reference) !== 1) {
            throw new GlobalIdentityKeyException('Invalid global identity provider reference.');
        }
    }

    /** @return array{outcome:string} */
    public function __debugInfo(): array
    {
        return ['outcome' => $this->outcome->value];
    }
}
