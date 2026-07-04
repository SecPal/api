<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\InteractsWithConfigValues;

final class AndroidPushDeliveryConfiguration
{
    use InteractsWithConfigValues;

    private const CANONICAL_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const CANONICAL_API_BASE_URL = 'https://fcm.googleapis.com';

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

        if ($this->configuredTokenUriIsInvalid()) {
            $missingFields[] = 'services.fcm.token_uri (present but invalid; must target https://oauth2.googleapis.com/token)';
        }

        if ($this->configuredApiBaseUrlIsInvalid()) {
            $missingFields[] = 'services.fcm.api_base_url (present but invalid; must target https://fcm.googleapis.com)';
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
        return $this->validatedTokenUri()
            ?? self::CANONICAL_TOKEN_URI;
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
        return $this->positiveIntegerConfig('services.fcm.connect_timeout', 5) ?? 5;
    }

    public function timeout(): int
    {
        return $this->positiveIntegerConfig('services.fcm.timeout', 10) ?? 10;
    }

    private function apiBaseUrl(): string
    {
        return $this->validatedApiBaseUrl()
            ?? self::CANONICAL_API_BASE_URL;
    }

    private function configuredTokenUriIsInvalid(): bool
    {
        $configuredTokenUri = $this->trimmedStringConfig('services.fcm.token_uri');

        return $configuredTokenUri !== null && $this->validatedTokenUri() === null;
    }

    private function configuredApiBaseUrlIsInvalid(): bool
    {
        $configuredApiBaseUrl = $this->trimmedStringConfig('services.fcm.api_base_url');

        return $configuredApiBaseUrl !== null && $this->validatedApiBaseUrl() === null;
    }

    private function validatedTokenUri(): ?string
    {
        return $this->canonicalGoogleUrl(
            $this->trimmedStringConfig('services.fcm.token_uri'),
            expectedHost: 'oauth2.googleapis.com',
            expectedPath: '/token',
        );
    }

    private function validatedApiBaseUrl(): ?string
    {
        return $this->canonicalGoogleUrl(
            $this->trimmedStringConfig('services.fcm.api_base_url'),
            expectedHost: 'fcm.googleapis.com',
            expectedPath: '/',
        );
    }

    private function canonicalGoogleUrl(?string $url, string $expectedHost, string $expectedPath): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $path = (string) ($parts['path'] ?? '/');
        $port = $parts['port'] ?? null;

        if ($scheme !== 'https' || $host !== $expectedHost) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        if ($port !== null && $port !== 443) {
            return null;
        }

        if ($expectedPath === '/') {
            if ($path !== '' && $path !== '/') {
                return null;
            }

            return sprintf('https://%s', $expectedHost);
        }

        if ($path !== $expectedPath && $path !== $expectedPath.'/') {
            return null;
        }

        return sprintf('https://%s%s', $expectedHost, $expectedPath);
    }
}
