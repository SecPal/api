<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Support;

final class BootstrapContract
{
    public const VERSION = 'v1';

    public const SCHEMA_VERSION = 2;

    public const NOTIFICATION_CHANNEL_ANDROID_FCM = 'android_fcm';

    public const NOTIFICATION_CHANNEL_WEB_PUSH = 'web_push';

    public const NOTIFICATION_CHANNELS = [
        self::NOTIFICATION_CHANNEL_ANDROID_FCM,
        self::NOTIFICATION_CHANNEL_WEB_PUSH,
    ];

    public const ANDROID_PUSH_PROVIDER = 'fcm';
}
