<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Contracts;

use App\Models\PushDeviceRegistration;

interface WebPushDeliveryServiceInterface
{
    /**
     * @param  array<string, string>  $data
     * @return array{delivered: bool, stale_subscription: bool, stale_reason: string|null, provider_status_code: int|null}
     */
    public function send(PushDeviceRegistration $registration, string $title, string $body, array $data = []): array;
}
