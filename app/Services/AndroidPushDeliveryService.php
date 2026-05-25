<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\PushDeviceRegistration;
use App\Support\AndroidPushDeliveryConfiguration;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class AndroidPushDeliveryService
{
    private const FIREBASE_MESSAGING_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function __construct(
        private readonly AndroidPushDeliveryConfiguration $configuration,
    ) {}

    /**
     * @param  array<string, string>  $data
     * @return array{delivered: bool, provider_message_id: string|null, invalid_token: bool, provider_error_code: string|null}
     */
    public function send(PushDeviceRegistration $registration, string $title, string $body, array $data = []): array
    {
        if ($this->configuration->missingFields() !== []) {
            throw new RuntimeException('Android push delivery is not configured for this deployment.');
        }

        $messageEndpoint = $this->configuration->messageEndpoint();

        if ($messageEndpoint === null) {
            throw new RuntimeException('Android push delivery is not configured for this deployment.');
        }

        $deviceToken = $registration->deliveryToken();

        if ($deviceToken === null) {
            throw new RuntimeException('Android push delivery requires a decryptable device token.');
        }

        $response = Http::acceptJson()
            ->withToken($this->fetchAccessToken())
            ->connectTimeout($this->configuration->connectTimeout())
            ->timeout($this->configuration->timeout())
            ->post($messageEndpoint, [
                'message' => [
                    'token' => $deviceToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => $this->normalizeMessageData($data),
                    'android' => [
                        'priority' => 'high',
                    ],
                ],
            ]);

        if ($response->successful()) {
            $providerMessageId = $response->json('name');

            if (! is_string($providerMessageId) || trim($providerMessageId) === '') {
                throw new RuntimeException('Firebase push delivery succeeded without returning a provider message id.');
            }

            return [
                'delivered' => true,
                'provider_message_id' => $providerMessageId,
                'invalid_token' => false,
                'provider_error_code' => null,
            ];
        }

        $responseBody = $response->json();
        $providerErrorCode = $this->providerErrorCode($responseBody);

        if ($this->shouldDeleteRegistration($providerErrorCode, $responseBody)) {
            $registration->delete();

            return [
                'delivered' => false,
                'provider_message_id' => null,
                'invalid_token' => true,
                'provider_error_code' => $providerErrorCode,
            ];
        }

        throw new RuntimeException(sprintf(
            'Firebase push delivery failed with HTTP %d%s.',
            $response->status(),
            $providerErrorCode === null ? '' : ' ('.$providerErrorCode.')',
        ));
    }

    private function fetchAccessToken(): string
    {
        $response = Http::asForm()
            ->acceptJson()
            ->connectTimeout($this->configuration->connectTimeout())
            ->timeout($this->configuration->timeout())
            ->post($this->configuration->tokenUri(), [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->buildJwtAssertion(),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Unable to fetch a Firebase access token for Android push delivery (HTTP %d).',
                $response->status(),
            ));
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || trim($accessToken) === '') {
            throw new RuntimeException('Firebase access token response did not include an access_token value.');
        }

        return $accessToken;
    }

    private function buildJwtAssertion(): string
    {
        $clientEmail = $this->configuration->clientEmail();
        $privateKey = $this->configuration->privateKey();

        if ($clientEmail === null || $privateKey === null) {
            throw new RuntimeException('Android push delivery is not configured for this deployment.');
        }

        $issuedAt = now()->getTimestamp();

        $encodedHeader = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $encodedPayload = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'sub' => $clientEmail,
            'aud' => $this->configuration->tokenUri(),
            'scope' => self::FIREBASE_MESSAGING_SCOPE,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ], JSON_THROW_ON_ERROR));

        $unsignedToken = $encodedHeader.'.'.$encodedPayload;
        $opensslKey = openssl_pkey_get_private($privateKey);

        if ($opensslKey === false) {
            throw new RuntimeException('Android push delivery private key is invalid.');
        }

        $signature = null;
        $signatureCreated = openssl_sign($unsignedToken, $signature, $opensslKey, OPENSSL_ALGO_SHA256);

        if (! $signatureCreated || ! is_string($signature)) {
            throw new RuntimeException('Unable to sign the Firebase service-account assertion.');
        }

        return $unsignedToken.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    private function normalizeMessageData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Android push message data keys must be non-empty strings.');
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException(sprintf('Android push message data value for "%s" must be a string.', $key));
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function providerErrorCode(mixed $responseBody): ?string
    {
        if (! is_array($responseBody)) {
            return null;
        }

        $details = data_get($responseBody, 'error.details');

        if (! is_array($details)) {
            return null;
        }

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $errorCode = $detail['errorCode'] ?? null;

            if (is_string($errorCode) && trim($errorCode) !== '') {
                return $errorCode;
            }
        }

        return null;
    }

    private function shouldDeleteRegistration(?string $providerErrorCode, mixed $responseBody): bool
    {
        if (in_array($providerErrorCode, ['UNREGISTERED', 'SENDER_ID_MISMATCH'], true)) {
            return true;
        }

        if (! is_array($responseBody)) {
            return false;
        }

        $status = data_get($responseBody, 'error.status');

        if ($providerErrorCode !== 'INVALID_ARGUMENT' && $status !== 'INVALID_ARGUMENT') {
            return false;
        }

        $message = data_get($responseBody, 'error.message');

        if (is_string($message) && str_contains(strtolower($message), 'registration token')) {
            return true;
        }

        $details = data_get($responseBody, 'error.details');

        if (! is_array($details)) {
            return false;
        }

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $fieldViolations = $detail['fieldViolations'] ?? null;

            if (! is_array($fieldViolations)) {
                continue;
            }

            foreach ($fieldViolations as $fieldViolation) {
                if (is_array($fieldViolation) && ($fieldViolation['field'] ?? null) === 'message.token') {
                    return true;
                }
            }
        }

        return false;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
