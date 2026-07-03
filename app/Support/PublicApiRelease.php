<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

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

    private function httpUrlValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $components = parse_url($trimmed);

        if ($components === false
            || ! isset($components['scheme'], $components['host'])
            || isset($components['user'])
            || isset($components['pass'])) {
            return null;
        }

        $scheme = strtolower((string) $components['scheme']);
        $host = $components['host'];

        if (! in_array($scheme, ['http', 'https'], true)
            || ! is_string($host)
            || $host === ''
            || strtolower($host) === 'localhost') {
            return null;
        }

        return $trimmed;
    }
}
