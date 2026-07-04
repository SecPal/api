<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PushDeviceRegistration;
use App\Services\AndroidPushDeliveryService;
use App\Support\AndroidPushDeliveryConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DeliverAndroidPushMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 30;

    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $pushDeviceRegistrationId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(AndroidPushDeliveryService $deliveryService, AndroidPushDeliveryConfiguration $configuration): void
    {
        $registration = PushDeviceRegistration::query()->find($this->pushDeviceRegistrationId);

        if (! $registration instanceof PushDeviceRegistration) {
            return;
        }

        if ($configuration->missingFields() !== []) {
            $this->fail('Android push delivery is not configured for this deployment.');

            return;
        }

        $deliveryService->send($registration, $this->title, $this->body, $this->data);
    }
}
