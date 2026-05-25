<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Support\NotificationChannelRuntimeConfiguration;

beforeEach(function (): void {
    config([
        'bootstrap.features.notification_channels.android_fcm' => true,
        'bootstrap.features.notification_channels.web_push' => false,
        'bootstrap.notification_channels.android_fcm.metadata_revision' => 3,
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.api_key' => 'public-client-api-key-demo-1234567890',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.project_id' => 'secpal-demo-push',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.application_id' => '1:1234567890:android:abcdef1234567890',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.sender_id' => '1234567890',
    ]);
});

test('feature flags are exhaustive for the active notification channels schema', function (): void {
    $configuration = new NotificationChannelRuntimeConfiguration;

    expect($configuration->featureFlags())->toBe([
        'android_fcm' => true,
        'web_push' => false,
    ]);
});

test('public runtime metadata is keyed by notification channel', function (): void {
    $configuration = new NotificationChannelRuntimeConfiguration;

    expect($configuration->publicRuntimeMetadata())->toBe([
        'android_fcm' => [
            'channel' => 'android_fcm',
            'metadata_revision' => 3,
            'public_runtime_metadata' => [
                'api_key' => 'public-client-api-key-demo-1234567890',
                'project_id' => 'secpal-demo-push',
                'application_id' => '1:1234567890:android:abcdef1234567890',
                'sender_id' => '1234567890',
            ],
        ],
    ]);
});

test('missing fields use channel aware runtime metadata paths', function (): void {
    config([
        'bootstrap.notification_channels.android_fcm.metadata_revision' => null,
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.api_key' => null,
    ]);

    $configuration = new NotificationChannelRuntimeConfiguration;

    expect($configuration->missingFields())->toBe([
        'notification_channels.android_fcm.metadata_revision',
        'notification_channels.android_fcm.public_runtime_metadata.api_key',
    ]);
});

test('legacy android push aliases backfill shared channel state when canonical keys are absent', function (): void {
    config([
        'bootstrap.features.notification_channels' => [],
        'bootstrap.notification_channels' => [],
        'bootstrap.features.android_push' => true,
        'bootstrap.android_push.metadata_revision' => 7,
        'bootstrap.android_push.public_client_metadata.api_key' => 'legacy-public-client-api-key',
        'bootstrap.android_push.public_client_metadata.project_id' => 'legacy-project-id',
        'bootstrap.android_push.public_client_metadata.application_id' => '1:9876543210:android:legacyabcdef',
        'bootstrap.android_push.public_client_metadata.sender_id' => '9876543210',
    ]);

    $configuration = new NotificationChannelRuntimeConfiguration;

    expect($configuration->featureFlags())->toBe([
        'android_fcm' => true,
        'web_push' => false,
    ])->and($configuration->publicRuntimeMetadata())->toBe([
        'android_fcm' => [
            'channel' => 'android_fcm',
            'metadata_revision' => 7,
            'public_runtime_metadata' => [
                'api_key' => 'legacy-public-client-api-key',
                'project_id' => 'legacy-project-id',
                'application_id' => '1:9876543210:android:legacyabcdef',
                'sender_id' => '9876543210',
            ],
        ],
    ]);
});

test('canonical null notification channel values do not fall back to legacy android push aliases', function (): void {
    config([
        'bootstrap.notification_channels.android_fcm.metadata_revision' => null,
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.api_key' => null,
        'bootstrap.android_push.metadata_revision' => 7,
        'bootstrap.android_push.public_client_metadata.api_key' => 'legacy-public-client-api-key',
    ]);

    $configuration = new NotificationChannelRuntimeConfiguration;

    expect($configuration->missingFields())->toContain(
        'notification_channels.android_fcm.metadata_revision',
        'notification_channels.android_fcm.public_runtime_metadata.api_key',
    )->and($configuration->publicRuntimeMetadata())->toBe([]);
});
