<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Contracts;

use App\Support\WebPushTransportResult;

interface WebPushTransportInterface
{
    /**
     * @param  array<string, mixed>  $subscription
     * @param  array<string, scalar>  $options
     */
    public function send(array $subscription, string $payload, array $options = []): WebPushTransportResult;
}
