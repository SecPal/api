<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use SensitiveParameter;

final readonly class TenantKekRotationPlan
{
    public function __construct(
        public string $operationId,
        public TenantKekReference $from,
        public TenantKekReference $to,
        #[SensitiveParameter] public string $sourcePath,
        #[SensitiveParameter] public string $targetPath,
    ) {
        if ($from->id !== $to->id || $from->generation !== $to->generation - 1
            || $from->fingerprint === $to->fingerprint || $sourcePath === $targetPath) {
            throw new RuntimeException('Invalid Tenant KEK rotation scope.');
        }
    }
}
