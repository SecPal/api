<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Support\WebPushDeliveryConfiguration;
use App\Support\WebPushTransportResult;
use GuzzleHttp\RequestOptions;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;
use Throwable;

class WebPushTransport
{
    public function __construct(
        private readonly WebPushDeliveryConfiguration $configuration,
    ) {}

    /**
     * @param  array<string, mixed>  $subscription
     * @param  array<string, scalar>  $options
     */
    public function send(array $subscription, string $payload, array $options = []): WebPushTransportResult
    {
        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $this->requiredConfigValue($this->configuration->subject()),
                    'publicKey' => $this->requiredConfigValue($this->configuration->publicKey()),
                    'privateKey' => $this->requiredConfigValue($this->configuration->privateKey()),
                ],
            ], [], null, [
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::CONNECT_TIMEOUT => $this->configuration->connectTimeout(),
                RequestOptions::TIMEOUT => $this->configuration->timeout(),
                RequestOptions::HTTP_ERRORS => false,
            ]);

            $report = $webPush->sendOneNotification(
                Subscription::create($subscription),
                $payload,
                $options,
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException('Web push delivery request failed before the push service responded.', previous: $throwable);
        }

        return new WebPushTransportResult(
            successful: $report->isSuccess(),
            statusCode: $report->getResponse()?->getStatusCode(),
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
}
