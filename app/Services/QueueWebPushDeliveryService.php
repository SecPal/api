<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverWebPushMessage;
use App\Models\PushDeviceRegistration;
use App\Support\BootstrapContract;

final class QueueWebPushDeliveryService
{
    /**
     * @param  array<int, string>  $registrationIds
     * @param  array<string, string>  $data
     * @return array<int, string>
     */
    public function dispatchToRegistrations(int $tenantId, array $registrationIds, string $title, string $body, array $data = []): array
    {
        $normalizedIds = $this->normalizeRegistrationIds($registrationIds);

        if ($normalizedIds === []) {
            return [];
        }

        $matchingLookup = [];

        foreach (PushDeviceRegistration::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $normalizedIds)
            ->where('platform', BootstrapContract::CLIENT_PLATFORM_BROWSER)
            ->where('provider', BootstrapContract::WEB_PUSH_PROVIDER)
            ->pluck('id') as $matchingId) {
            if (is_string($matchingId) && $matchingId !== '') {
                $matchingLookup[$matchingId] = true;
            }
        }

        $queuedIds = [];

        foreach ($normalizedIds as $registrationId) {
            if (! isset($matchingLookup[$registrationId])) {
                continue;
            }

            DeliverWebPushMessage::dispatch($registrationId, $title, $body, $data);
            $queuedIds[] = $registrationId;
        }

        return $queuedIds;
    }

    /**
     * @param  array<int, string>  $registrationIds
     * @return array<int, string>
     */
    private function normalizeRegistrationIds(array $registrationIds): array
    {
        $normalizedIds = [];

        foreach ($registrationIds as $registrationId) {
            if (! is_string($registrationId)) {
                continue;
            }

            $trimmedId = trim($registrationId);

            if ($trimmedId === '' || in_array($trimmedId, $normalizedIds, true)) {
                continue;
            }

            $normalizedIds[] = $trimmedId;
        }

        return $normalizedIds;
    }
}
