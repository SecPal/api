<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\InteractsWithConfigValues;

class PublicSourceOffer
{
    use InteractsWithConfigValues;

    public function canonicalSourceUrl(): ?string
    {
        $configuredUrl = $this->trimmedStringConfig('app.url');

        if ($configuredUrl === null || $configuredUrl === 'http://localhost') {
            return null;
        }

        $components = parse_url($configuredUrl);

        if ($components === false
            || ! isset($components['scheme'], $components['host'])
            || isset($components['user'])
            || isset($components['pass'])
            || isset($components['query'])
            || isset($components['fragment'])) {
            return null;
        }

        $scheme = strtolower((string) $components['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = $components['host'];

        if (! is_string($host) || $host === '' || strtolower($host) === 'localhost') {
            return null;
        }

        $authority = $host;

        if (isset($components['port']) && is_int($components['port'])) {
            $authority .= ':'.$components['port'];
        }

        $rawPath = rtrim((string) ($components['path'] ?? ''), '/');

        if ($rawPath !== '' && $rawPath !== '/v1') {
            return null;
        }

        return $scheme.'://'.$authority.'/v1/source';
    }

    /**
     * @return array{
     *     license: array{spdx_id: string, name: string, url: string, base_license_url: string},
     *     source_url: string
     * }
     */
    public function bootstrapMetadata(string $sourceUrl): array
    {
        return [
            'license' => $this->licenseMetadata(),
            'source_url' => $sourceUrl,
        ];
    }

    /**
     * @return array{
     *     source_url: string,
     *     notice: string,
     *     source_offer: string,
     *     license: array{spdx_id: string, name: string, url: string, base_license_url: string},
     *     repositories: array<int, array{name: string, url: string, description: string}>,
     *     copyright_notice: string,
     *     warranty_notice: string
     * }
     */
    public function sourceResponseData(string $sourceUrl): array
    {
        return [
            'source_url' => $sourceUrl,
            'notice' => 'Source offer for users interacting with SecPal over a network.',
            'source_offer' => 'Corresponding source for the SecPal components made available through this service.',
            'license' => $this->licenseMetadata(),
            'repositories' => $this->repositories(),
            'copyright_notice' => $this->copyrightNotice(),
            'warranty_notice' => $this->warrantyNotice(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        $missingFields = [];

        if ($this->trimmedStringConfig('bootstrap.legal.license_spdx_id') === null) {
            $missingFields[] = 'legal.license.spdx_id';
        }

        if ($this->trimmedStringConfig('bootstrap.legal.license_name') === null) {
            $missingFields[] = 'legal.license.name';
        }

        if ($this->httpUrlConfig('bootstrap.legal.license_url') === null) {
            $missingFields[] = 'legal.license.url';
        }

        if ($this->httpUrlConfig('bootstrap.legal.license_base_url') === null) {
            $missingFields[] = 'legal.license.base_license_url';
        }

        if ($this->trimmedStringConfig('bootstrap.legal.copyright_notice') === null) {
            $missingFields[] = 'legal.copyright_notice';
        }

        if ($this->trimmedStringConfig('bootstrap.legal.warranty_notice') === null) {
            $missingFields[] = 'legal.warranty_notice';
        }

        $repositories = config('bootstrap.legal.source_repositories');

        if (! is_array($repositories) || $repositories === []) {
            $missingFields[] = 'legal.source_repositories';

            return $missingFields;
        }

        foreach ($repositories as $index => $repository) {
            if (! is_array($repository)) {
                $missingFields[] = "legal.source_repositories.{$index}";

                continue;
            }

            $name = $this->trimmedRepositoryValue($repository['name'] ?? null);
            $url = $this->httpUrlValue($repository['url'] ?? null);
            $description = $this->trimmedRepositoryValue($repository['description'] ?? null);

            if ($name === null) {
                $missingFields[] = "legal.source_repositories.{$index}.name";
            }

            if ($url === null) {
                $missingFields[] = "legal.source_repositories.{$index}.url";
            }

            if ($description === null) {
                $missingFields[] = "legal.source_repositories.{$index}.description";
            }
        }

        return $missingFields;
    }

    /**
     * @return array{spdx_id: string, name: string, url: string, base_license_url: string}
     */
    private function licenseMetadata(): array
    {
        return [
            'spdx_id' => (string) $this->trimmedStringConfig('bootstrap.legal.license_spdx_id'),
            'name' => (string) $this->trimmedStringConfig('bootstrap.legal.license_name'),
            'url' => (string) $this->httpUrlConfig('bootstrap.legal.license_url'),
            'base_license_url' => (string) $this->httpUrlConfig('bootstrap.legal.license_base_url'),
        ];
    }

    /**
     * @return array<int, array{name: string, url: string, description: string}>
     */
    private function repositories(): array
    {
        $repositories = config('bootstrap.legal.source_repositories');

        if (! is_array($repositories)) {
            return [];
        }

        $normalized = [];

        foreach ($repositories as $repository) {
            if (! is_array($repository)) {
                continue;
            }

            $name = $this->trimmedRepositoryValue($repository['name'] ?? null);
            $url = $this->httpUrlValue($repository['url'] ?? null);
            $description = $this->trimmedRepositoryValue($repository['description'] ?? null);

            if ($name === null || $url === null || $description === null) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'url' => $url,
                'description' => $description,
            ];
        }

        return $normalized;
    }

    private function copyrightNotice(): string
    {
        return (string) $this->trimmedStringConfig('bootstrap.legal.copyright_notice');
    }

    private function warrantyNotice(): string
    {
        return (string) $this->trimmedStringConfig('bootstrap.legal.warranty_notice');
    }

    private function trimmedRepositoryValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function httpUrlConfig(string $key): ?string
    {
        return $this->httpUrlValue(config($key));
    }
}
