<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Support;

final class AndroidPushRuntimeConfiguration
{
    public function isEnabled(): bool
    {
        return $this->booleanConfig('bootstrap.features.android_push', false);
    }

    public function metadataRevision(): ?int
    {
        $value = config('bootstrap.android_push.metadata_revision');

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $missingFields = [];

        if ($this->metadataRevision() === null) {
            $missingFields[] = 'android_push.metadata_revision';
        }

        foreach (['api_key', 'project_id', 'application_id', 'sender_id'] as $field) {
            if ($this->publicClientMetadataValue($field) === null) {
                $missingFields[] = 'android_push.public_client_metadata.'.$field;
            }
        }

        return $missingFields;
    }

    public function publicMetadata(): ?array
    {
        if (! $this->isEnabled() || $this->missingFields() !== []) {
            return null;
        }

        return [
            'provider' => BootstrapContract::ANDROID_PUSH_PROVIDER,
            'metadata_revision' => $this->metadataRevision(),
            'public_client_metadata' => [
                'api_key' => $this->publicClientMetadataValue('api_key'),
                'project_id' => $this->publicClientMetadataValue('project_id'),
                'application_id' => $this->publicClientMetadataValue('application_id'),
                'sender_id' => $this->publicClientMetadataValue('sender_id'),
            ],
        ];
    }

    private function publicClientMetadataValue(string $field): ?string
    {
        return $this->trimmedStringConfig('bootstrap.android_push.public_client_metadata.'.$field);
    }

    private function booleanConfig(string $key, bool $default): bool
    {
        $value = config($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $default;
    }

    private function trimmedStringConfig(string $key): ?string
    {
        $value = config($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
