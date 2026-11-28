<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use SensitiveParameter;

/** @phpstan-import-type Envelope from GlobalIdentityEnvelopeCodec */
final readonly class GlobalIdentityOperationCodec
{
    public function __construct(private GlobalIdentityEnvelopeCodec $envelopes = new GlobalIdentityEnvelopeCodec) {}

    /** @return Envelope */
    public function sealToken(
        GlobalIdentityOperationContext $operation,
        #[SensitiveParameter] string $token,
        GlobalIdentityWorkingKeyContext $context,
        #[SensitiveParameter] string $key,
    ): array {
        $this->requirePurpose($context, GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret);
        self::validateToken($token);

        return $this->envelopes->seal($token, $context, $key, $operation->bindingFields());
    }

    /** @param array<mixed> $envelope */
    public function tokenReference(#[SensitiveParameter] array $envelope): GlobalIdentityWorkingKeyContext
    {
        return $this->envelopes->reference($envelope, GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret);
    }

    /** @param array<mixed> $envelope */
    public function openToken(
        GlobalIdentityOperationContext $operation,
        #[SensitiveParameter] array $envelope,
        #[SensitiveParameter] string $key,
    ): string {
        $token = $this->envelopes->open($envelope, GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret, $key, $operation->bindingFields());
        self::validateToken($token);

        return $token;
    }

    /** @return Envelope */
    public function sealRecipient(
        GlobalIdentityOperationContext $operation,
        #[SensitiveParameter] string $recipient,
        GlobalIdentityWorkingKeyContext $context,
        #[SensitiveParameter] string $key,
    ): array {
        $this->requirePurpose($context, $this->recipientPurpose($operation));
        $address = GlobalEmailAddress::parse($recipient);

        return $this->envelopes->seal($address->original(), $context, $key, $operation->bindingFields());
    }

    /** @param array<mixed> $envelope */
    public function recipientReference(
        GlobalIdentityOperationContext $operation,
        #[SensitiveParameter] array $envelope,
    ): GlobalIdentityWorkingKeyContext {
        return $this->envelopes->reference($envelope, $this->recipientPurpose($operation));
    }

    /** @param array<mixed> $envelope */
    public function openRecipient(
        GlobalIdentityOperationContext $operation,
        #[SensitiveParameter] array $envelope,
        #[SensitiveParameter] string $key,
    ): GlobalEmailAddress {
        $recipient = $this->envelopes->open($envelope, $this->recipientPurpose($operation), $key, $operation->bindingFields());
        try {
            $address = GlobalEmailAddress::parse($recipient);
            if ($address->original() !== $recipient) {
                throw new GlobalIdentityKeyException('Invalid global identity recipient envelope.');
            }

            return $address;
        } finally {
            sodium_memzero($recipient);
        }
    }

    public static function hashToken(#[SensitiveParameter] string $token): string
    {
        self::validateToken($token);

        // The canonical token contains 256 independently random bits.
        return hash('sha256', $token);
    }

    private static function validateToken(#[SensitiveParameter] string $token): void
    {
        if (strlen($token) !== 43) {
            throw new GlobalIdentityKeyException('Invalid global identity operation token.');
        }
        $raw = GlobalIdentityEnvelopeCodec::decode($token);
        try {
            if (strlen($raw) !== 32) {
                throw new GlobalIdentityKeyException('Invalid global identity operation token.');
            }
        } finally {
            sodium_memzero($raw);
        }
    }

    private function recipientPurpose(GlobalIdentityOperationContext $operation): GlobalIdentityWorkingKeyPurpose
    {
        return $operation->type->recipientPurpose()
            ?? throw new GlobalIdentityKeyException('Invalid global identity recipient context.');
    }

    private function requirePurpose(GlobalIdentityWorkingKeyContext $context, GlobalIdentityWorkingKeyPurpose $purpose): void
    {
        if ($context->purpose !== $purpose) {
            throw new GlobalIdentityKeyException('Invalid global identity operation key purpose.');
        }
    }
}
