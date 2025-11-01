<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TenantKey model for managing per-tenant envelope encryption keys.
 *
 * This model stores wrapped Data Encryption Keys (DEK) and Index Keys (idx_key)
 * using envelope encryption with a Key Encryption Key (KEK).
 *
 * @property int $id
 * @property string $dek_wrapped BYTEA wrapped DEK
 * @property string $dek_nonce BYTEA nonce for DEK
 * @property string $idx_wrapped BYTEA wrapped idx_key
 * @property string $idx_nonce BYTEA nonce for idx_key
 * @property int $key_version Key version for rotation
 * @property \Illuminate\Support\Carbon $created_at
 */
class TenantKey extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tenant_keys';

    /**
     * Indicates that the model should not use the updated_at column.
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'dek_wrapped',
        'dek_nonce',
        'idx_wrapped',
        'idx_nonce',
        'key_version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * Binary fields are stored as VARCHAR columns with base64 encoding;
     * the Binary custom cast handles base64 encoding/decoding.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dek_wrapped' => \App\Casts\Binary::class,
            'dek_nonce' => \App\Casts\Binary::class,
            'idx_wrapped' => \App\Casts\Binary::class,
            'idx_nonce' => \App\Casts\Binary::class,
            'key_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'key_version' => 1,
    ];

    /**
     * Get the foreign key column name for the tenant.
     */
    public function getTenantIdColumn(): string
    {
        return 'id'; // tenant_keys.id is the tenant identifier
    }

    /**
     * Get the path to the KEK file.
     */
    protected static function getKekPath(): string
    {
        return storage_path('app/keys/kek.key');
    }

    /**
     * Load the Key Encryption Key (KEK) from storage.
     *
     * @throws \RuntimeException if KEK file is missing
     */
    protected static function loadKek(): string
    {
        $path = self::getKekPath();

        if (! file_exists($path)) {
            throw new \RuntimeException('KEK file not found at: '.$path);
        }

        $kek = file_get_contents($path);

        if ($kek === false || strlen($kek) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid KEK file');
        }

        return $kek;
    }

    /**
     * Generate a new KEK and store it securely.
     *
     * @throws \RuntimeException if unable to create directory or write file
     */
    public static function generateKek(): void
    {
        $path = self::getKekPath();
        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Failed to create keys directory');
        }

        $kek = sodium_crypto_secretbox_keygen();

        if (file_put_contents($path, $kek) === false) {
            throw new \RuntimeException('Failed to write KEK file');
        }

        chmod($path, 0600);
    }

    /**
     * Generate new envelope keys (DEK and idx_key) wrapped with KEK.
     *
     * @return array{dek_wrapped: string, dek_nonce: string, idx_wrapped: string, idx_nonce: string}
     *
     * @throws \RuntimeException if KEK is not available
     */
    public static function generateEnvelopeKeys(): array
    {
        $kek = self::loadKek();

        // Generate Data Encryption Key (DEK)
        $dek = sodium_crypto_secretbox_keygen();
        $dekNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $dekWrapped = sodium_crypto_secretbox($dek, $dekNonce, $kek);

        // Generate Index Key for HMAC-SHA256 blind indexes (32 bytes)
        $idxKey = sodium_crypto_secretbox_keygen();
        $idxNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $idxWrapped = sodium_crypto_secretbox($idxKey, $idxNonce, $kek);

        sodium_memzero($kek);
        sodium_memzero($dek);
        sodium_memzero($idxKey);

        return [
            'dek_wrapped' => $dekWrapped,
            'dek_nonce' => $dekNonce,
            'idx_wrapped' => $idxWrapped,
            'idx_nonce' => $idxNonce,
        ];
    }

    /**
     * Unwrap the Data Encryption Key (DEK).
     *
     * @throws \RuntimeException if unwrapping fails
     */
    public function unwrapDek(): string
    {
        $kek = self::loadKek();

        $dek = sodium_crypto_secretbox_open(
            $this->dek_wrapped,
            $this->dek_nonce,
            $kek
        );

        sodium_memzero($kek);

        if ($dek === false) {
            throw new \RuntimeException('Failed to unwrap DEK');
        }

        return $dek;
    }

    /**
     * Unwrap the Index Key for blind indexes.
     *
     * @throws \RuntimeException if unwrapping fails
     */
    public function unwrapIdxKey(): string
    {
        $kek = self::loadKek();

        $idxKey = sodium_crypto_secretbox_open(
            $this->idx_wrapped,
            $this->idx_nonce,
            $kek
        );

        sodium_memzero($kek);

        if ($idxKey === false) {
            throw new \RuntimeException('Failed to unwrap idx_key');
        }

        return $idxKey;
    }

    /**
     * Encrypt plaintext data using the tenant's DEK.
     *
     * @return array{ciphertext: string, nonce: string}
     *
     * @throws \RuntimeException if encryption fails
     */
    public function encrypt(string $plaintext): array
    {
        $dek = $this->unwrapDek();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $dek);

        sodium_memzero($dek);

        return [
            'ciphertext' => $ciphertext,
            'nonce' => $nonce,
        ];
    }

    /**
     * Decrypt ciphertext using the tenant's DEK.
     *
     * @throws \RuntimeException if decryption fails
     */
    public function decrypt(string $ciphertext, string $nonce): string
    {
        $dek = $this->unwrapDek();
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $dek);

        sodium_memzero($dek);

        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt data');
        }

        return $plaintext;
    }

    /**
     * Generate a blind index for searchable encrypted fields.
     *
     * Uses HMAC-SHA256 with the tenant's index key.
     */
    public function generateBlindIndex(string $plaintext): string
    {
        $idxKey = $this->unwrapIdxKey();
        $index = hash_hmac('sha256', $plaintext, $idxKey, true);

        sodium_memzero($idxKey);

        return $index;
    }
}
