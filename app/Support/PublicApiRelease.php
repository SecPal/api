<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\InteractsWithConfigValues;

class PublicApiRelease
{
    use InteractsWithConfigValues;

    /**
     * @return array{version: string, source_url: string}
     */
    public function responseData(): array
    {
        return [
            'version' => (string) $this->trimmedStringConfig('bootstrap.api_release.version'),
            'source_url' => (string) $this->httpUrlConfig('bootstrap.api_release.source_url'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        $missingFields = [];

        if ($this->trimmedStringConfig('bootstrap.api_release.version') === null) {
            $missingFields[] = 'api_release.version';
        }

        if ($this->httpUrlConfig('bootstrap.api_release.source_url') === null) {
            $missingFields[] = 'api_release.source_url';
        }

        return $missingFields;
    }

    private function httpUrlConfig(string $key): ?string
    {
        return $this->httpUrlValue(config($key));
    }
}
