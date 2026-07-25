<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WebPushTransportInterface;
use App\Support\WebPushDeliveryConfiguration;
use App\Support\WebPushTransportResult;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;
use Throwable;

final class WebPushTransport implements WebPushTransportInterface
{
    /**
     * @var list<string>
     */
    private const ALLOWED_PUSH_SERVICE_HOST_SUFFIXES = [
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
    ];

    public function __construct(
        private readonly WebPushDeliveryConfiguration $configuration,
    ) {}

    /**
     * @param  array<string, mixed>  $subscription
     * @param  array<string, scalar>  $options
     */
    public function send(array $subscription, string $payload, array $options = []): WebPushTransportResult
    {
        $vapidConfig = [
            'subject' => $this->requiredConfigValue($this->configuration->subject()),
            'publicKey' => $this->requiredConfigValue($this->configuration->publicKey()),
            'privateKey' => $this->requiredConfigValue($this->configuration->privateKey()),
        ];

        $this->assertAllowedPushServiceEndpoint($subscription['endpoint'] ?? null);

        try {
            $webPush = new WebPush([
                'VAPID' => $vapidConfig,
            ], [], new Client([
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::CONNECT_TIMEOUT => $this->configuration->connectTimeout(),
                RequestOptions::TIMEOUT => $this->configuration->timeout(),
                RequestOptions::HTTP_ERRORS => false,
            ]));

            $report = $webPush->sendOneNotification(
                Subscription::create($subscription),
                $payload,
                $options,
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException('Web push delivery request failed before the push service responded.', previous: $throwable);
        }

        $statusCode = $report->getResponse()?->getStatusCode();

        return new WebPushTransportResult(
            successful: $statusCode !== null
                ? $statusCode >= 200 && $statusCode < 300
                : $report->isSuccess(),
            statusCode: $statusCode,
            subscriptionExpired: $report->isSubscriptionExpired(),
        );
    }

    private function requiredConfigValue(?string $value): string
    {
        if ($value === null) {
            throw new RuntimeException('Web push delivery is not configured for this deployment.');
        }

        return $value;
    }

    private function assertAllowedPushServiceEndpoint(mixed $endpoint): void
    {
        if (! is_string($endpoint) || trim($endpoint) === '') {
            throw new RuntimeException('Web push delivery requires an allowed browser push service endpoint.');
        }

        $host = parse_url($endpoint, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            throw new RuntimeException('Web push delivery requires an allowed browser push service endpoint.');
        }

        $normalizedHost = strtolower(rtrim($host, '.'));

        foreach (self::ALLOWED_PUSH_SERVICE_HOST_SUFFIXES as $allowedSuffix) {
            if ($normalizedHost === $allowedSuffix || str_ends_with($normalizedHost, '.'.$allowedSuffix)) {
                return;
            }
        }

        throw new RuntimeException('Web push delivery requires an allowed browser push service endpoint.');
    }
}
