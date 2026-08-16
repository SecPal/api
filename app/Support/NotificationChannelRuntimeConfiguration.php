<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\InteractsWithConfigValues;

final class NotificationChannelRuntimeConfiguration
{
    use InteractsWithConfigValues;

    /**
     * @var array<string, list<string>>
     */
    private const CLIENT_PLATFORM_CHANNELS = [
        BootstrapContract::CLIENT_PLATFORM_ANDROID => [
            BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM,
        ],
        BootstrapContract::CLIENT_PLATFORM_BROWSER => [
            BootstrapContract::NOTIFICATION_CHANNEL_WEB_PUSH,
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const PUBLIC_RUNTIME_FIELDS = [
        BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM => [
            'api_key',
            'project_id',
            'application_id',
            'sender_id',
        ],
        BootstrapContract::NOTIFICATION_CHANNEL_WEB_PUSH => [
            'vapid_public_key',
        ],
    ];

    /**
     * @return array<string, bool>
     */
    public function featureFlags(): array
    {
        $featureFlags = [];

        foreach (BootstrapContract::NOTIFICATION_CHANNELS as $channel) {
            $featureFlags[$channel] = $this->isEnabled($channel);
        }

        return $featureFlags;
    }

    public function isEnabled(string $channel): bool
    {
        return $this->booleanConfig($this->featureFlagKey($channel), false);
    }

    public function metadataRevision(string $channel): ?int
    {
        return $this->positiveIntegerValue(config($this->metadataRevisionConfigKey($channel)));
    }

    /**
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        $missingFields = [];

        foreach (BootstrapContract::NOTIFICATION_CHANNELS as $channel) {
            array_push($missingFields, ...$this->missingFieldsFor($channel));
        }

        return $missingFields;
    }

    /**
     * @return array<int, string>
     */
    public function missingFieldsForClientPlatform(string $clientPlatform): array
    {
        $missingFields = [];

        foreach ($this->channelsForClientPlatform($clientPlatform) as $channel) {
            array_push($missingFields, ...$this->missingFieldsFor($channel));
        }

        return $missingFields;
    }

    /**
     * @return array<int, string>
     */
    public function missingFieldsFor(string $channel): array
    {
        if (! $this->isEnabled($channel)) {
            return [];
        }

        $missingFields = [];
        $metadataRevisionFieldPath = $this->metadataRevisionFieldPath($channel);
        $rawRevision = config($this->metadataRevisionConfigKey($channel));

        if ($rawRevision === null) {
            $missingFields[] = $metadataRevisionFieldPath;
        } elseif ($this->metadataRevision($channel) === null) {
            $missingFields[] = $metadataRevisionFieldPath.' (present but invalid; must be a positive integer)';
        }

        foreach ($this->publicRuntimeFields($channel) as $field) {
            if ($this->publicRuntimeMetadataValue($channel, $field) === null) {
                $missingFields[] = $this->publicRuntimeMetadataFieldPath($channel, $field);
            }
        }

        return $missingFields;
    }

    /**
     * @return array<string, array{channel: string, metadata_revision: int, public_runtime_metadata: array<string, string>}>
     */
    public function publicRuntimeMetadata(): array
    {
        $metadata = [];

        foreach (BootstrapContract::NOTIFICATION_CHANNELS as $channel) {
            $channelMetadata = $this->runtimeMetadataFor($channel);

            if ($channelMetadata !== null) {
                $metadata[$channel] = $channelMetadata;
            }
        }

        return $metadata;
    }

    /**
     * @return array<string, array{channel: string, metadata_revision: int, public_runtime_metadata: array<string, string>}>
     */
    public function publicRuntimeMetadataForClientPlatform(string $clientPlatform): array
    {
        $metadata = [];

        foreach ($this->channelsForClientPlatform($clientPlatform) as $channel) {
            $channelMetadata = $this->runtimeMetadataFor($channel);

            if ($channelMetadata !== null) {
                $metadata[$channel] = $channelMetadata;
            }
        }

        return $metadata;
    }

    /**
     * @return array{channel: string, metadata_revision: int, public_runtime_metadata: array<string, string>}|null
     */
    public function runtimeMetadataFor(string $channel): ?array
    {
        if (! $this->isEnabled($channel) || $this->missingFieldsFor($channel) !== []) {
            return null;
        }

        $metadataRevision = $this->metadataRevision($channel);

        if ($metadataRevision === null) {
            return null;
        }

        $publicRuntimeMetadata = [];

        foreach ($this->publicRuntimeFields($channel) as $field) {
            $value = $this->publicRuntimeMetadataValue($channel, $field);

            if ($value === null) {
                return null;
            }

            $publicRuntimeMetadata[$field] = $value;
        }

        return [
            'channel' => $channel,
            'metadata_revision' => $metadataRevision,
            'public_runtime_metadata' => $publicRuntimeMetadata,
        ];
    }

    private function featureFlagKey(string $channel): string
    {
        return 'bootstrap.features.notification_channels.'.$channel;
    }

    private function metadataRevisionConfigKey(string $channel): string
    {
        return 'bootstrap.notification_channels.'.$channel.'.metadata_revision';
    }

    private function metadataRevisionFieldPath(string $channel): string
    {
        return 'notification_channels.'.$channel.'.metadata_revision';
    }

    private function publicRuntimeMetadataConfigKey(string $channel, string $field): string
    {
        return 'bootstrap.notification_channels.'.$channel.'.public_runtime_metadata.'.$field;
    }

    private function publicRuntimeMetadataFieldPath(string $channel, string $field): string
    {
        return 'notification_channels.'.$channel.'.public_runtime_metadata.'.$field;
    }

    /**
     * @return list<string>
     */
    private function publicRuntimeFields(string $channel): array
    {
        return self::PUBLIC_RUNTIME_FIELDS[$channel] ?? [];
    }

    private function publicRuntimeMetadataValue(string $channel, string $field): ?string
    {
        return $this->trimmedStringValue(config($this->publicRuntimeMetadataConfigKey($channel, $field)));
    }

    /**
     * @return list<string>
     */
    private function channelsForClientPlatform(string $clientPlatform): array
    {
        return self::CLIENT_PLATFORM_CHANNELS[$clientPlatform] ?? BootstrapContract::NOTIFICATION_CHANNELS;
    }

    private function trimmedStringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function positiveIntegerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/', $value)) {
            return null;
        }

        return (int) $value;
    }
}
