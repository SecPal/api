<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Support;

final class BootstrapContract
{
    public const VERSION = 'v1';

    public const SCHEMA_VERSION = 4;

    /**
     * Remove schema 3 only after the minimum supported Android release no
     * longer restores or submits schema-3 runtime state. Remove it from the
     * frontend allowlist and update the Android runtime contract at the same
     * time. Tracked by SecPal/api#1359 under SecPal/.github#589.
     */
    public const NOTIFICATION_REGISTRATION_SCHEMA_VERSIONS = [
        3,
        4,
    ];

    public const CLIENT_PLATFORM_ANDROID = 'android';

    public const CLIENT_PLATFORM_BROWSER = 'browser';

    public const NOTIFICATION_CHANNEL_ANDROID_FCM = 'android_fcm';

    public const NOTIFICATION_CHANNEL_WEB_PUSH = 'web_push';

    public const NOTIFICATION_CHANNELS = [
        self::NOTIFICATION_CHANNEL_ANDROID_FCM,
        self::NOTIFICATION_CHANNEL_WEB_PUSH,
    ];

    public const ANDROID_PUSH_PROVIDER = 'fcm';

    public const WEB_PUSH_PROVIDER = self::NOTIFICATION_CHANNEL_WEB_PUSH;

    public const NOTIFICATION_INSTALLATION_LIFECYCLE_EVENTS = [
        'registered',
        'credential_rotated',
        'client_updated',
    ];
}
