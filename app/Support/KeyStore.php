<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Support;

use App\Models\TenantKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Key management service for tenant-specific encryption keys.
 *
 * Uses libsodium XChaCha20-Poly1305 (AEAD) for wrapping/unwrapping keys.
 * KEK (Key Encryption Key) is loaded from file, DEK and idx_key are stored
 * encrypted in database per tenant.
 */
class KeyStore
{
    private ?string $kekCache = null;

    /**
     * Load the Key Encryption Key (KEK) from file.
     *
     * @throws \RuntimeException If KEK file is missing or invalid
     */
    public function loadKek(): string
    {
        if ($this->kekCache !== null) {
            return $this->kekCache;
        }

        $kekPath = config('keys.kek_path');

        if (! file_exists($kekPath)) {
            throw new \RuntimeException("KEK file not found: {$kekPath}");
        }

        // Check file permissions (should be 0600)
        $perms = fileperms($kekPath);
        if (($perms & 0077) !== 0) {
            Log::warning('KEK file has insecure permissions (should be 0600)', [
                'path' => $kekPath,
                'permissions' => sprintf('%o', $perms & 0777),
            ]);
        }

        $kek = file_get_contents($kekPath);

        // Support both Base64 and raw binary
        if (preg_match('/^[A-Za-z0-9+\/=\s]+$/', $kek)) {
            $decoded = base64_decode(trim($kek), true);
            if ($decoded !== false) {
                $kek = $decoded;
            }
        }

        if (strlen($kek) < SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(sprintf(
                'KEK too short: %d bytes (minimum %d bytes required)',
                strlen($kek),
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            ));
        }

        // Use first 32 bytes if longer
        $kek = substr($kek, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        $this->kekCache = $kek;

        return $kek;
    }

    /**
     * Generate a new random encryption key.
     */
    public function generateKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Wrap (encrypt) a key with KEK using libsodium secretbox.
     *
     * @return array{wrapped: string, nonce: string}
     */
    public function wrapKey(string $plainKey, string $kek): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $wrapped = sodium_crypto_secretbox($plainKey, $nonce, $kek);

        return [
            'wrapped' => $wrapped,
            'nonce' => $nonce,
        ];
    }

    /**
     * Unwrap (decrypt) the index key for a tenant.
     *
     * @throws \RuntimeException If tenant keys not found or decryption fails
     */
    public function unwrapIdxKeyForTenant(string $tenantId): string
    {
        $cacheTtl = config('keys.cache_ttl', 5);

        if ($cacheTtl <= 0) {
            return $this->doUnwrapIdxKey($tenantId);
        }

        return Cache::remember(
            "idx_key:{$tenantId}",
            now()->addMinutes($cacheTtl),
            fn () => $this->doUnwrapIdxKey($tenantId)
        );
    }

    /**
     * Unwrap (decrypt) the DEK for a tenant.
     *
     * @throws \RuntimeException If tenant keys not found or decryption fails
     */
    public function unwrapDekForTenant(string $tenantId): string
    {
        $cacheTtl = config('keys.cache_ttl', 5);

        if ($cacheTtl <= 0) {
            return $this->doUnwrapDek($tenantId);
        }

        return Cache::remember(
            "dek:{$tenantId}",
            now()->addMinutes($cacheTtl),
            fn () => $this->doUnwrapDek($tenantId)
        );
    }

    /**
     * Clear cached keys (call after rotation).
     */
    public function clearCache(?string $tenantId = null): void
    {
        $this->kekCache = null;

        if ($tenantId) {
            Cache::forget("idx_key:{$tenantId}");
            Cache::forget("dek:{$tenantId}");
        } else {
            Cache::flush();
        }
    }

    /**
     * Perform actual index key unwrapping.
     */
    private function doUnwrapIdxKey(string $tenantId): string
    {
        $tenantKey = TenantKey::find($tenantId);

        if (! $tenantKey) {
            throw new \RuntimeException("Tenant keys not found for: {$tenantId}");
        }

        $kek = $this->loadKek();

        return $this->decrypt(
            $tenantKey->idx_wrapped,
            $tenantKey->idx_nonce,
            $kek,
            'index key'
        );
    }

    /**
     * Perform actual DEK unwrapping.
     */
    private function doUnwrapDek(string $tenantId): string
    {
        $tenantKey = TenantKey::find($tenantId);

        if (! $tenantKey) {
            throw new \RuntimeException("Tenant keys not found for: {$tenantId}");
        }

        $kek = $this->loadKek();

        return $this->decrypt(
            $tenantKey->dek_wrapped,
            $tenantKey->dek_nonce,
            $kek,
            'DEK'
        );
    }

    /**
     * Decrypt wrapped key using libsodium secretbox.
     *
     * @throws \RuntimeException If decryption fails
     */
    private function decrypt(string $wrapped, string $nonce, string $kek, string $keyType): string
    {
        $plainKey = sodium_crypto_secretbox_open($wrapped, $nonce, $kek);

        if ($plainKey === false) {
            throw new \RuntimeException("{$keyType} decryption failed (corrupted data or wrong KEK)");
        }

        return $plainKey;
    }
}
