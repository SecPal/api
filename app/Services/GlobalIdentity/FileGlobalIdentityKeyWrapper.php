<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Contracts\GlobalIdentityKeyWrapper;
use App\Exceptions\GlobalIdentityKeyException;
use Random\RandomException;
use SensitiveParameter;
use SodiumException;

final readonly class FileGlobalIdentityKeyWrapper implements GlobalIdentityKeyWrapper
{
    private const FORMAT_VERSION = 1;

    private const ALGORITHM = 'XCHACHA20-POLY1305-IETF';

    private const WORKING_KEY_BYTES = 32;

    private const AUTHENTICATION_FAILED = 'Global identity key authentication failed.';

    public function __construct(private FileGlobalIdentityRootProvider $provider) {}

    public function wrap(
        #[SensitiveParameter] string $key,
        GlobalIdentityWorkingKeyContext $workingKey,
        GlobalIdentityKeyReference $root,
    ): WrappedGlobalIdentityKey {
        if (strlen($key) !== self::WORKING_KEY_BYTES) {
            throw new GlobalIdentityKeyException('Invalid global identity working-key length.');
        }

        $rootKey = $this->provider->load($root);
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $key,
                $this->associatedData($workingKey, $root),
                $nonce,
                $rootKey,
            );
        } catch (RandomException|SodiumException) {
            throw new GlobalIdentityKeyException('Global identity key wrapping failed.');
        } finally {
            sodium_memzero($rootKey);
        }

        return new WrappedGlobalIdentityKey(self::FORMAT_VERSION, self::ALGORITHM, $root, $nonce, $ciphertext);
    }

    public function unwrap(#[SensitiveParameter] WrappedGlobalIdentityKey $wrapped, GlobalIdentityWorkingKeyContext $workingKey): string
    {
        if ($wrapped->formatVersion !== self::FORMAT_VERSION || $wrapped->algorithm !== self::ALGORITHM
            || strlen($wrapped->nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || strlen($wrapped->ciphertextAndTag) !== self::WORKING_KEY_BYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new GlobalIdentityKeyException('Invalid wrapped global identity key.');
        }

        $rootKey = $this->provider->load($wrapped->root);
        try {
            $key = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $wrapped->ciphertextAndTag,
                $this->associatedData($workingKey, $wrapped->root),
                $wrapped->nonce,
                $rootKey,
            );
        } catch (SodiumException) {
            throw new GlobalIdentityKeyException(self::AUTHENTICATION_FAILED);
        } finally {
            sodium_memzero($rootKey);
        }

        if ($key === false) {
            throw new GlobalIdentityKeyException(self::AUTHENTICATION_FAILED);
        }

        return $key;
    }

    private function associatedData(GlobalIdentityWorkingKeyContext $workingKey, GlobalIdentityKeyReference $root): string
    {
        $fields = [
            (string) self::FORMAT_VERSION,
            self::ALGORITHM,
            $workingKey->purpose->value,
            $workingKey->reference->id,
            (string) $workingKey->reference->version,
            $root->id,
            (string) $root->version,
        ];

        $encoded = '';
        foreach ($fields as $field) {
            $encoded .= pack('N', strlen($field)).$field;
        }

        return $encoded;
    }
}
