<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final readonly class TenantKekReference
{
    public function __construct(public string $id, public int $generation, public string $fingerprint)
    {
        if (! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $id)
            || $generation < 1
            || ! preg_match('/\A[0-9a-f]{64}\z/', $fingerprint)) {
            throw new RuntimeException('Invalid Tenant KEK reference.');
        }
    }

    public function matches(self $other): bool
    {
        return $this->id === $other->id
            && $this->generation === $other->generation
            && hash_equals($this->fingerprint, $other->fingerprint);
    }
}
