<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use Illuminate\Support\Str;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;
use SensitiveParameter;
use SodiumException;

/** @phpstan-type EmailEnvelope array{format_version: 1, algorithm: 'XCHACHA20-POLY1305-IETF', key_id: string, key_version: int, nonce: string, ciphertext_and_tag: string} */
final readonly class GlobalEmailCodec
{
    private const FORMAT = 1;

    private const ALGORITHM = 'XCHACHA20-POLY1305-IETF';

    private const BASE64 = SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING;

    public function __construct(private Randomizer $nonces = new Randomizer(new Secure)) {}

    /** @return EmailEnvelope */
    public function encrypt(
        string $userId,
        #[SensitiveParameter] GlobalEmailAddress $address,
        GlobalIdentityWorkingKeyContext $context,
        #[SensitiveParameter] string $key,
    ): array {
        $aad = $this->aad($userId, $context, $key);
        try {
            $nonce = $this->nonces->getBytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($address->original(), $aad, $nonce, $key);
        } catch (RandomException|SodiumException) {
            throw new GlobalIdentityKeyException('Global email encryption failed.');
        }

        return [
            'format_version' => self::FORMAT,
            'algorithm' => self::ALGORITHM,
            'key_id' => $context->reference->id,
            'key_version' => $context->reference->version,
            'nonce' => sodium_bin2base64($nonce, self::BASE64),
            'ciphertext_and_tag' => sodium_bin2base64($ciphertext, self::BASE64),
        ];
    }

    /**
     * @param  array<mixed>  $envelope
     *
     * @phpstan-assert EmailEnvelope $envelope
     */
    public function reference(#[SensitiveParameter] array $envelope): GlobalIdentityWorkingKeyContext
    {
        $id = $envelope['key_id'] ?? null;
        $version = $envelope['key_version'] ?? null;
        if (count($envelope) !== 6 || ($envelope['format_version'] ?? null) !== self::FORMAT
            || ($envelope['algorithm'] ?? null) !== self::ALGORITHM
            || ! is_string($id) || ! $this->isCanonicalKeyId($id) || ! is_int($version) || $version < 1
            || ! is_string($envelope['nonce'] ?? null) || ! is_string($envelope['ciphertext_and_tag'] ?? null)) {
            throw new GlobalIdentityKeyException('Invalid global email envelope.');
        }

        return new GlobalIdentityWorkingKeyContext(
            GlobalIdentityWorkingKeyPurpose::EmailEncryption,
            new GlobalIdentityKeyReference($id, $version),
        );
    }

    /** @param array<mixed> $envelope */
    public function decrypt(
        string $userId,
        #[SensitiveParameter] array $envelope,
        #[SensitiveParameter] string $key,
    ): GlobalEmailAddress {
        $context = $this->reference($envelope);
        $aad = $this->aad($userId, $context, $key);
        $nonce = $this->decode($envelope['nonce']);
        $ciphertext = $this->decode($envelope['ciphertext_and_tag']);
        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || strlen($ciphertext) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new GlobalIdentityKeyException('Invalid global email envelope.');
        }

        try {
            $email = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $key);
        } catch (SodiumException) {
            throw new GlobalIdentityKeyException('Invalid global email envelope.');
        }

        if ($email === false) {
            throw new GlobalIdentityKeyException('Invalid global email envelope.');
        }

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

    private function aad(string $userId, GlobalIdentityWorkingKeyContext $context, #[SensitiveParameter] string $key): string
    {
        if (! Str::isUuid($userId) || $context->purpose !== GlobalIdentityWorkingKeyPurpose::EmailEncryption
            || ! $this->isCanonicalKeyId($context->reference->id)
            || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new GlobalIdentityKeyException('Invalid global email encryption context.');
        }

        return GlobalIdentityCanonicalEncoding::encode(
            (string) self::FORMAT,
            self::ALGORITHM,
            $context->purpose->value,
            $context->reference->id,
            (string) $context->reference->version,
            'user',
            'email_enc',
            strtolower($userId),
        );
    }

    private function isCanonicalKeyId(string $id): bool
    {
        return $id === strtolower($id) && Str::isUuid($id);
    }

    private function decode(#[SensitiveParameter] string $encoded): string
    {
        try {
            $binary = sodium_base642bin($encoded, self::BASE64);
        } catch (SodiumException) {
            throw new GlobalIdentityKeyException('Invalid global email envelope.');
        }

        if (sodium_bin2base64($binary, self::BASE64) !== $encoded) {
            throw new GlobalIdentityKeyException('Invalid global email envelope.');
        }

        return $binary;
    }
}
