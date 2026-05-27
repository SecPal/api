<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CorruptedEncryptedAttributeException;
use App\Models\PushDeviceRegistration;
use App\Support\PushMessageDataNormalizer;
use App\Support\WebPushDeliveryConfiguration;
use JsonException;
use RuntimeException;

class WebPushDeliveryService
{
    public function __construct(
        private readonly WebPushDeliveryConfiguration $configuration,
        private readonly WebPushTransport $transport,
    ) {}

    /**
     * @param  array<string, string>  $data
     * @return array{delivered: bool, stale_subscription: bool, stale_reason: string|null, provider_status_code: int|null}
     */
    public function send(PushDeviceRegistration $registration, string $title, string $body, array $data = []): array
    {
        if ($this->configuration->missingFields() !== []) {
            throw new RuntimeException('Web push delivery is not configured for this deployment.');
        }

        if ($this->isExpired($registration)) {
            $registration->delete();

            return $this->staleSubscriptionResult('subscription_expired');
        }

        try {
            $subscription = $this->subscriptionPayload($registration);
        } catch (CorruptedEncryptedAttributeException) {
            $registration->delete();

            return $this->staleSubscriptionResult('decryption_failure');
        }

        try {
            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'data' => PushMessageDataNormalizer::normalize($data, 'Web push'),
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the Web push delivery payload.', previous: $exception);
        }

        $result = $this->transport->send($subscription, $payload, [
            'TTL' => $this->configuration->ttl(),
            'urgency' => $this->configuration->urgency(),
            'contentType' => 'application/json',
        ]);

        if ($result->successful) {
            return [
                'delivered' => true,
                'stale_subscription' => false,
                'stale_reason' => null,
                'provider_status_code' => $result->statusCode,
            ];
        }

        if ($result->subscriptionExpired || in_array($result->statusCode, [404, 410], true)) {
            $registration->delete();

            return $this->staleSubscriptionResult('subscription_expired', $result->statusCode);
        }

        if ($result->statusCode === null) {
            throw new RuntimeException('Web push delivery failed before the push service responded.');
        }

        throw new RuntimeException(sprintf('Web push delivery failed with HTTP %d.', $result->statusCode));
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(PushDeviceRegistration $registration): array
    {
        $endpoint = $registration->webPushEndpoint();
        $p256dh = $registration->webPushP256dh();
        $auth = $registration->webPushAuth();

        if ($endpoint === null || $p256dh === null || $auth === null) {
            throw new RuntimeException('Web push delivery requires a decryptable browser subscription.');
        }

        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => $p256dh,
                'auth' => $auth,
            ],
        ];
    }

    /**
     * @return array{delivered: false, stale_subscription: true, stale_reason: string, provider_status_code: int|null}
     */
    private function staleSubscriptionResult(string $reason, ?int $statusCode = null): array
    {
        return [
            'delivered' => false,
            'stale_subscription' => true,
            'stale_reason' => $reason,
            'provider_status_code' => $statusCode,
        ];
    }

    private function isExpired(PushDeviceRegistration $registration): bool
    {
        return $registration->subscription_expires_at !== null
            && $registration->subscription_expires_at->lte(now());
    }
}
