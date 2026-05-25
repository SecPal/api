<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\InteractsWithConfigValues;

final class AndroidPushDeliveryConfiguration
{
    use InteractsWithConfigValues;

    /**
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        $missingFields = [];

        if ($this->projectId() === null) {
            $missingFields[] = 'services.fcm.project_id';
        }

        if ($this->clientEmail() === null) {
            $missingFields[] = 'services.fcm.client_email';
        }

        if ($this->privateKey() === null) {
            $missingFields[] = 'services.fcm.private_key';
        }

        return $missingFields;
    }

    public function projectId(): ?string
    {
        return $this->trimmedStringConfig('services.fcm.project_id');
    }

    public function clientEmail(): ?string
    {
        return $this->trimmedStringConfig('services.fcm.client_email');
    }

    public function privateKey(): ?string
    {
        $privateKey = $this->trimmedStringConfig('services.fcm.private_key');

        if ($privateKey === null) {
            return null;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", str_replace('\\n', "\n", $privateKey));

        return trim($normalized) === '' ? null : $normalized;
    }

    public function tokenUri(): string
    {
        return $this->trimmedStringConfig('services.fcm.token_uri')
            ?? 'https://oauth2.googleapis.com/token';
    }

    public function messageEndpoint(): ?string
    {
        $projectId = $this->projectId();

        if ($projectId === null) {
            return null;
        }

        return sprintf('%s/v1/projects/%s/messages:send', $this->apiBaseUrl(), rawurlencode($projectId));
    }

    public function connectTimeout(): int
    {
        return $this->positiveIntegerConfig('services.fcm.connect_timeout', 5);
    }

    public function timeout(): int
    {
        return $this->positiveIntegerConfig('services.fcm.timeout', 10);
    }

    private function apiBaseUrl(): string
    {
        return rtrim($this->trimmedStringConfig('services.fcm.api_base_url') ?? 'https://fcm.googleapis.com', '/');
    }

    private function positiveIntegerConfig(string $key, int $default): int
    {
        $value = config($key);

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            return (int) $value;
        }

        return $default;
    }
}
