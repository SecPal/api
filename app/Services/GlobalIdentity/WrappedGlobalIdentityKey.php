<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

final readonly class WrappedGlobalIdentityKey
{
    public function __construct(
        public int $formatVersion,
        public string $algorithm,
        public GlobalIdentityKeyReference $root,
        public string $nonce,
        public string $ciphertextAndTag,
    ) {}
}
