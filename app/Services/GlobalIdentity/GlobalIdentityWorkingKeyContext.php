<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

final readonly class GlobalIdentityWorkingKeyContext
{
    public function __construct(
        public GlobalIdentityWorkingKeyPurpose $purpose,
        public GlobalIdentityKeyReference $reference,
    ) {}
}
