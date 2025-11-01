<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Casts;

use App\Support\KeyStore;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Custom cast for tenant-specific DEK encryption with libsodium AEAD.
 *
 * Encrypts data using tenant-specific Data Encryption Key (DEK).
 * Nonces are stored separately in {field}_nonce columns.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class TenantEncrypted implements CastsAttributes
{
    /**
     * Cast the given value from storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Derive nonce column name: email_enc -> email_nonce (not email_enc_nonce)
        $nonceKey = str_replace('_enc', '_nonce', $key);
        if (! isset($attributes[$nonceKey])) {
            throw new RuntimeException("Nonce column {$nonceKey} not found for encrypted field {$key}");
        }

        // Get tenant-specific DEK
        $keyStore = app(KeyStore::class);
        $dek = $keyStore->unwrapDekForTenant($model->tenant_id);

        // Decrypt using libsodium AEAD
        $ciphertext = is_resource($value) ? stream_get_contents($value) : $value;
        $nonce = is_resource($attributes[$nonceKey]) ? stream_get_contents($attributes[$nonceKey]) : $attributes[$nonceKey];

        $plaintext = sodium_crypto_aead_aes256gcm_decrypt(
            ciphertext: $ciphertext,
            additional_data: '',
            nonce: $nonce,
            key: $dek
        );

        if ($plaintext === false) {
            throw new RuntimeException("Failed to decrypt field {$key} for tenant {$model->tenant_id}");
        }

        sodium_memzero($dek);

        return $plaintext;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        // Derive nonce column name: email_enc -> email_nonce (not email_enc_nonce)
        $nonceKey = str_replace('_enc', '_nonce', $key);

        if ($value === null) {
            return [
                $key => null,
                $nonceKey => null,
            ];
        }

        // Get tenant-specific DEK
        $keyStore = app(KeyStore::class);
        $dek = $keyStore->unwrapDekForTenant($model->tenant_id);

        // Generate nonce (12 bytes for AES-256-GCM)
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);

        // Encrypt using libsodium AEAD
        $ciphertext = sodium_crypto_aead_aes256gcm_encrypt(
            message: $value,
            additional_data: '',
            nonce: $nonce,
            key: $dek
        );

        sodium_memzero($dek);

        return [
            $key => $ciphertext,
            $nonceKey => $nonce,
        ];
    }
}
