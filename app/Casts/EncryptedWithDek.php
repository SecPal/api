<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Casts;

use App\Exceptions\CorruptedEncryptedAttributeException;
use App\Exceptions\TenantKeyDecryptionException;
use App\Models\TenantKey;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast for encrypting attributes using the tenant's DEK (Data Encryption Key).
 *
 * This cast:
 * - GET: Decrypts ciphertext using tenant DEK (via TenantKey::decrypt)
 * - SET: Encrypts plaintext using tenant DEK (via TenantKey::encrypt)
 *
 * Storage format in DB: JSON with {ciphertext: base64, nonce: base64}
 * This allows proper key rotation via keys:rotate-dek command.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class EncryptedWithDek implements CastsAttributes
{
    /**
     * Cast the given value from storage (decrypt).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new CorruptedEncryptedAttributeException("Expected string value for {$key}, got ".gettype($value));
        }

        // Decode JSON structure: {ciphertext: base64, nonce: base64}
        $data = json_decode($value, true);
        if (! is_array($data) || ! isset($data['ciphertext'], $data['nonce'])) {
            throw new CorruptedEncryptedAttributeException("Invalid encrypted data format for {$key}");
        }

        if (! is_string($data['ciphertext']) || ! is_string($data['nonce'])) {
            throw new CorruptedEncryptedAttributeException("Invalid ciphertext/nonce types for {$key}");
        }

        $ciphertext = base64_decode($data['ciphertext'], true);
        $nonce = base64_decode($data['nonce'], true);

        if ($ciphertext === false || $nonce === false) {
            throw new CorruptedEncryptedAttributeException("Failed to decode base64 data for {$key}");
        }

        // Get tenant and decrypt
        /** @var TenantKey $tenant */
        $tenant = TenantKey::findOrFail($attributes['tenant_id']);

        try {
            return $tenant->decrypt($ciphertext, $nonce);
        } catch (TenantKeyDecryptionException $exception) {
            throw new CorruptedEncryptedAttributeException(
                "Failed to decrypt encrypted data for {$key}",
                previous: $exception,
            );
        }
    }

    /**
     * Prepare the given value for storage (encrypt).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new \RuntimeException("Expected string value for {$key}, got ".gettype($value));
        }

        // Get tenant and encrypt
        /** @var TenantKey $tenant */
        $tenant = TenantKey::findOrFail($attributes['tenant_id']);
        $encrypted = $tenant->encrypt($value);

        // Store as JSON: {ciphertext: base64, nonce: base64}
        $json = json_encode([
            'ciphertext' => base64_encode($encrypted['ciphertext']),
            'nonce' => base64_encode($encrypted['nonce']),
        ]);

        if ($json === false) {
            throw new \RuntimeException("Failed to encode encrypted data for {$key}");
        }

        return $json;
    }
}
