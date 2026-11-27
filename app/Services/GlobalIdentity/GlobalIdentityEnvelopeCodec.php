<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use Exception;
use Illuminate\Support\Str;
use Random\Engine\Secure;
use Random\Randomizer;
use SensitiveParameter;
use SodiumException;

/** @phpstan-type Envelope array{format_version: 1, algorithm: 'XCHACHA20-POLY1305-IETF', key_id: string, key_version: int, nonce: string, ciphertext_and_tag: string} */
final readonly class GlobalIdentityEnvelopeCodec
{
    private const FORMAT = 1;

    private const ALGORITHM = 'XCHACHA20-POLY1305-IETF';

    public const BASE64 = SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING;

    public function __construct(private Randomizer $nonces = new Randomizer(new Secure)) {}

    /**
     * @param  list<string>  $binding
     * @return Envelope
     */
    public function seal(
        #[SensitiveParameter] string $plaintext,
        GlobalIdentityWorkingKeyContext $context,
        #[SensitiveParameter] string $key,
        array $binding,
    ): array {
        $aad = $this->aad($context, $key, $binding);
        if ($plaintext === '') {
            throw new GlobalIdentityKeyException('Invalid global identity envelope plaintext.');
        }

        try {
            $nonce = $this->nonces->getBytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
        } catch (Exception) {
            throw new GlobalIdentityKeyException('Global identity envelope encryption failed.');
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
     * @phpstan-assert Envelope $envelope
     */
    public function reference(
        #[SensitiveParameter] array $envelope,
        GlobalIdentityWorkingKeyPurpose $purpose,
    ): GlobalIdentityWorkingKeyContext {
        $id = $envelope['key_id'] ?? null;
        $version = $envelope['key_version'] ?? null;
        if (count($envelope) !== 6 || ($envelope['format_version'] ?? null) !== self::FORMAT
            || ($envelope['algorithm'] ?? null) !== self::ALGORITHM || $purpose->algorithm() !== self::ALGORITHM
            || ! is_string($id) || ! $this->isCanonicalKeyId($id) || ! is_int($version) || $version < 1
            || ! is_string($envelope['nonce'] ?? null) || ! is_string($envelope['ciphertext_and_tag'] ?? null)) {
            throw new GlobalIdentityKeyException('Invalid global identity envelope.');
        }

        return new GlobalIdentityWorkingKeyContext($purpose, new GlobalIdentityKeyReference($id, $version));
    }

    /**
     * @param  array<mixed>  $envelope
     * @param  list<string>  $binding
     */
    public function open(
        #[SensitiveParameter] array $envelope,
        GlobalIdentityWorkingKeyPurpose $purpose,
        #[SensitiveParameter] string $key,
        array $binding,
    ): string {
        $context = $this->reference($envelope, $purpose);
        $aad = $this->aad($context, $key, $binding);
        $nonce = self::decode($envelope['nonce']);
        $ciphertext = self::decode($envelope['ciphertext_and_tag']);
        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || strlen($ciphertext) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new GlobalIdentityKeyException('Invalid global identity envelope.');
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $key);
        } catch (SodiumException) {
            throw new GlobalIdentityKeyException('Invalid global identity envelope.');
        }
        if ($plaintext === false) {
            throw new GlobalIdentityKeyException('Invalid global identity envelope.');
        }

        return $plaintext;
    }

    /** @param list<string> $binding */
    private function aad(
        GlobalIdentityWorkingKeyContext $context,
        #[SensitiveParameter] string $key,
        array $binding,
    ): string {
        if ($context->purpose->algorithm() !== self::ALGORITHM || ! $this->isCanonicalKeyId($context->reference->id)
            || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new GlobalIdentityKeyException('Invalid global identity encryption context.');
        }

        return GlobalIdentityCanonicalEncoding::encode(
            (string) self::FORMAT,
            self::ALGORITHM,
            $context->purpose->value,
            $context->reference->id,
            (string) $context->reference->version,
            ...$binding,
        );
    }

    private function isCanonicalKeyId(string $id): bool
    {
        return $id === strtolower($id) && Str::isUuid($id);
    }

    public static function decode(#[SensitiveParameter] string $encoded): string
    {
        try {
            $binary = sodium_base642bin($encoded, self::BASE64);
        } catch (SodiumException) {
            throw new GlobalIdentityKeyException('Invalid global identity binary encoding.');
        }

        if (sodium_bin2base64($binary, self::BASE64) !== $encoded) {
            throw new GlobalIdentityKeyException('Invalid global identity binary encoding.');
        }

        return $binary;
    }
}
