<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Services\WebPushTransport;
use App\Support\WebPushTransportResult;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;

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
            'p256dh' => 'BAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE',
            'auth' => 'AgICAgICAgICAgICAgICAg',
        ],
    ], '{"title":"Compliance alert"}'))
        ->toThrow(RuntimeException::class, 'Web push delivery is not configured for this deployment.');
});

test('transport rejects subscription endpoints outside the allowed browser push services', function (): void {
    expect(fn (): mixed => app(WebPushTransport::class)->send([
        'endpoint' => 'https://internal.service/push/subscription',
        'contentEncoding' => 'aes128gcm',
        'keys' => [
            'p256dh' => 'BAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE',
            'auth' => 'AgICAgICAgICAgICAgICAg',
        ],
    ], '{"title":"Compliance alert"}'))
        ->toThrow(RuntimeException::class, 'Web push delivery requires an allowed browser push service endpoint.');
});

test('transport preserves non-successful provider responses from the web push library', function (): void {
    $report = new MessageSentReport(
        new Request('POST', 'https://fcm.googleapis.com/fcm/send/example-subscription'),
        new Response(503),
    );

    Mockery::mock('overload:Minishlink\\WebPush\\WebPush')
        ->shouldReceive('__construct')
        ->once()
        ->withArgs(function (array $authentication, array $defaultOptions, Client $client): bool {
            return $authentication['VAPID']['subject'] === 'mailto:notifications@secpal.dev'
                && $defaultOptions === []
                && $client->getConfig('allow_redirects') === false
                && $client->getConfig('connect_timeout') === 5
                && $client->getConfig('timeout') === 20
                && $client->getConfig('http_errors') === false;
        })
        ->shouldReceive('sendOneNotification')
        ->once()
        ->andReturn($report);

    $result = app(WebPushTransport::class)->send([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/example-subscription',
        'contentEncoding' => 'aes128gcm',
        'keys' => [
            'p256dh' => 'BAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE',
            'auth' => 'AgICAgICAgICAgICAgICAg',
        ],
    ], '{"title":"Compliance alert"}');

    expect($result)->toBeInstanceOf(WebPushTransportResult::class)
        ->and($result->successful)->toBeFalse()
        ->and($result->statusCode)->toBe(503)
        ->and($result->subscriptionExpired)->toBeFalse();
});
