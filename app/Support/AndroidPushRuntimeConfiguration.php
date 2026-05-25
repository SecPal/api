<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Support;

final class AndroidPushRuntimeConfiguration
{
    public function __construct(
        private readonly NotificationChannelRuntimeConfiguration $notificationChannelRuntimeConfiguration,
    ) {}

    public function isEnabled(): bool
    {
        return $this->notificationChannelRuntimeConfiguration->isEnabled(BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM);
    }

    public function metadataRevision(): ?int
    {
        return $this->notificationChannelRuntimeConfiguration->metadataRevision(BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM);
    }

    /**
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        return $this->notificationChannelRuntimeConfiguration->missingFieldsFor(BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM);
    }

    /**
     * @return array{provider: string, metadata_revision: int, public_client_metadata: array{api_key: string, project_id: string, application_id: string, sender_id: string}}|null
     */
    public function publicMetadata(): ?array
    {
        $runtimeMetadata = $this->notificationChannelRuntimeConfiguration->runtimeMetadataFor(BootstrapContract::NOTIFICATION_CHANNEL_ANDROID_FCM);

        if ($runtimeMetadata === null) {
            return null;
        }

        $publicRuntimeMetadata = $runtimeMetadata['public_runtime_metadata'];
        $apiKey = $publicRuntimeMetadata['api_key'] ?? null;
        $projectId = $publicRuntimeMetadata['project_id'] ?? null;
        $applicationId = $publicRuntimeMetadata['application_id'] ?? null;
        $senderId = $publicRuntimeMetadata['sender_id'] ?? null;

        if (! is_string($apiKey)
            || ! is_string($projectId)
            || ! is_string($applicationId)
            || ! is_string($senderId)) {
            return null;
        }

        return [
            'provider' => BootstrapContract::ANDROID_PUSH_PROVIDER,
            'metadata_revision' => $runtimeMetadata['metadata_revision'],
            'public_client_metadata' => [
                'api_key' => $apiKey,
                'project_id' => $projectId,
                'application_id' => $applicationId,
                'sender_id' => $senderId,
            ],
        ];
    }
}
