<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use Illuminate\Support\Str;
use Random\Engine\Secure;
use Random\Randomizer;
use SensitiveParameter;

/** @phpstan-import-type Envelope from GlobalIdentityEnvelopeCodec */
final readonly class GlobalEmailCodec
{
    private GlobalIdentityEnvelopeCodec $envelopes;

    public function __construct(Randomizer $nonces = new Randomizer(new Secure))
    {
        $this->envelopes = new GlobalIdentityEnvelopeCodec($nonces);
    }

    /** @return Envelope */
    public function encrypt(
        string $userId,
        #[SensitiveParameter] GlobalEmailAddress $address,
        GlobalIdentityWorkingKeyContext $context,
        #[SensitiveParameter] string $key,
    ): array {
        if ($context->purpose !== GlobalIdentityWorkingKeyPurpose::EmailEncryption) {
            throw new GlobalIdentityKeyException('Invalid global email encryption context.');
        }

        return $this->envelopes->seal($address->original(), $context, $key, $this->binding($userId));
    }

    /** @param array<mixed> $envelope */
    public function reference(#[SensitiveParameter] array $envelope): GlobalIdentityWorkingKeyContext
    {
        return $this->envelopes->reference($envelope, GlobalIdentityWorkingKeyPurpose::EmailEncryption);
    }

    /** @param array<mixed> $envelope */
    public function decrypt(
        string $userId,
        #[SensitiveParameter] array $envelope,
        #[SensitiveParameter] string $key,
    ): GlobalEmailAddress {
        $email = $this->envelopes->open($envelope, GlobalIdentityWorkingKeyPurpose::EmailEncryption, $key, $this->binding($userId));

        try {
            $address = GlobalEmailAddress::parse($email);
            if ($address->original() !== $email) {
                throw new GlobalIdentityKeyException('Invalid global email envelope.');
            }

            return $address;
        } finally {
            sodium_memzero($email);
        }
    }

    public function lookupIndex(#[SensitiveParameter] GlobalEmailAddress $address, #[SensitiveParameter] string $key): string
    {
        if (strlen($key) !== 32) {
            throw new GlobalIdentityKeyException('Invalid global email index key.');
        }

        return hash_hmac('sha256', GlobalIdentityCanonicalEncoding::encode(
            GlobalIdentityWorkingKeyPurpose::UserEmailLookup->value,
            (string) GlobalEmailAddress::NORMALIZATION_VERSION,
            $address->normalized(),
        ), $key, true);
    }

    /** @return list<string> */
    private function binding(string $userId): array
    {
        if (! Str::isUuid($userId)) {
            throw new GlobalIdentityKeyException('Invalid global email encryption context.');
        }

        return ['user', 'email_enc', strtolower($userId)];
    }
}
