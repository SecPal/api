<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Services\WebPushTransport;

beforeEach(function (): void {
    config([
        'services.web_push.public_key' => 'BE9tfo-aCxwtPk9QYXKDlAUGBwgJCgsMDQ4PEBESExQVobLD1OX2BxgpMEFSY3SFlgcYKTBLXG1-j5ABAgMEBQY',
        'services.web_push.subject' => 'mailto:notifications@secpal.dev',
        'services.web_push.private_key' => '4AqAyV7R7cFAKE4tYEXAMPLEd91SOA45Qjmj1UzYQ0Wc',
        'services.web_push.connect_timeout' => 5,
        'services.web_push.timeout' => 20,
    ]);
});

test('transport surfaces the direct missing web push configuration error', function (): void {
    config([
        'services.web_push.subject' => null,
    ]);

    expect(fn (): mixed => app(WebPushTransport::class)->send([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/example-subscription',
        'keys' => [
            'p256dh' => 'BElx7P1qA2rS9tUvWxYz0123456789abcdefghijklmnopqrstuv',
            'auth' => 'K7d9Lm2PqRs',
        ],
    ], '{"title":"Compliance alert"}'))
        ->toThrow(RuntimeException::class, 'Web push delivery is not configured for this deployment.');
});

test('transport rejects subscription endpoints outside the allowed browser push services', function (): void {
    expect(fn (): mixed => app(WebPushTransport::class)->send([
        'endpoint' => 'https://internal.service/push/subscription',
        'contentEncoding' => 'aes128gcm',
        'keys' => [
            'p256dh' => 'BElx7P1qA2rS9tUvWxYz0123456789abcdefghijklmnopqrstuv',
            'auth' => 'K7d9Lm2PqRs',
        ],
    ], '{"title":"Compliance alert"}'))
        ->toThrow(RuntimeException::class, 'Web push delivery requires an allowed browser push service endpoint.');
});
