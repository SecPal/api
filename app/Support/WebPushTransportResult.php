<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Support;

final readonly class WebPushTransportResult
{
    public function __construct(
        public bool $successful,
        public ?int $statusCode,
        public bool $subscriptionExpired,
    ) {}
}
