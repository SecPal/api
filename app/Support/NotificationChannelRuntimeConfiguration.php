<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\InteractsWithConfigValues;
use Illuminate\Support\Arr;

final class NotificationChannelRuntimeConfiguration
{
    use InteractsWithConfigValues;

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
        $featureFlagKey = $this->featureFlagKey($channel);

        if ($this->hasConfig($featureFlagKey)) {
            return $this->booleanConfig($featureFlagKey, false);
        }

        return match ($channel) {
            BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM => $this->booleanConfig('bootstrap.features.android_push', false),
            default => false,
        };
    }

    public function metadataRevision(string $channel): ?int
    {
        return $this->positiveIntegerValue($this->configValue(
            $this->metadataRevisionConfigKey($channel),
            $this->legacyMetadataRevisionKey($channel),
        ));
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
    public function missingFieldsFor(string $channel): array
    {
        if (! $this->isEnabled($channel)) {
            return [];
        }

        $missingFields = [];
        $metadataRevisionFieldPath = $this->metadataRevisionFieldPath($channel);
        $rawRevision = $this->configValue($this->metadataRevisionConfigKey($channel), $this->legacyMetadataRevisionKey($channel));

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

    private function legacyMetadataRevisionKey(string $channel): ?string
    {
        return match ($channel) {
            BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM => 'bootstrap.android_push.metadata_revision',
            default => null,
        };
    }

    private function legacyPublicRuntimeMetadataKey(string $channel, string $field): ?string
    {
        return match ($channel) {
            BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM => 'bootstrap.android_push.public_client_metadata.'.$field,
            default => null,
        };
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
        return $this->trimmedStringValue($this->configValue(
            $this->publicRuntimeMetadataConfigKey($channel, $field),
            $this->legacyPublicRuntimeMetadataKey($channel, $field),
        ));
    }

    private function configValue(string $canonicalKey, ?string $legacyKey = null): mixed
    {
        if ($this->hasConfig($canonicalKey)) {
            return config($canonicalKey);
        }

        return $legacyKey === null ? config($canonicalKey) : config($legacyKey);
    }

    private function hasConfig(string $key): bool
    {
        /** @var array<string, mixed> $all */
        $all = config()->all();

        return Arr::has($all, $key);
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
