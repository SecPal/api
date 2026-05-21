<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $users
 */
class TenantKey extends Model
{
    /** @use HasFactory<\Database\Factories\TenantKeyFactory> */
    use HasFactory;

    /**
     * File system permissions for keys directory (owner read/write/execute only)
     */
    private const KEY_DIRECTORY_PERMISSIONS = 0700;

    /**
     * File system permissions required for the KEK file (owner read/write only)
     */
    private const KEK_FILE_PERMISSIONS = 0600;

    /**
     * Permission mask used to normalize file mode comparisons
     */
    private const FILE_PERMISSION_MASK = 0777;

    /**
     * HMAC algorithm used for blind index generation
     */
    private const HMAC_ALGORITHM = 'sha256';

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
     * Get all users belonging to this tenant.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<User, $this>
     */
    public function users(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    /**
     * Path to the KEK file (can be overridden for testing).
     */
    protected static ?string $kekPath = null;

    /**
     * Readable checker override (for test isolation only).
     *
     * @var (callable(string): bool)|null
     */
    private static mixed $readableChecker = null;

    /**
     * Get the path to the KEK file.
     */
    public static function getKekPath(): string
    {
        $configuredPath = config('app.kek_path');

        return static::$kekPath
            ?? (is_string($configuredPath) && $configuredPath !== ''
                ? $configuredPath
                : storage_path('app/keys/kek.key'));
    }

    /**
     * Set the path to the KEK file.
     */
    public static function setKekPath(?string $path): void
    {
        static::$kekPath = $path;
    }

    /**
     * Override the readable check for test isolation. Pass null to restore default behaviour.
     *
     * @internal Only for use in tests.
     *
     * @param  (callable(string): bool)|null  $checker
     */
    public static function setReadableChecker(?callable $checker): void
    {
        self::$readableChecker = $checker;
    }

    /**
     * Load the Key Encryption Key (KEK) from storage.
     *
     * @throws \RuntimeException if KEK file is missing, unreadable, or has insecure permissions
     */
    public static function loadKek(): string
    {
        $path = self::getKekPath();

        if (! file_exists($path)) {
            throw new \RuntimeException('KEK file not found at: '.$path);
        }

        self::assertSecureKekPermissions($path);
        self::assertReadableKekFile($path);

        $kek = file_get_contents($path);

        if ($kek === false || strlen($kek) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid KEK file');
        }

        return $kek;
    }

    /**
     * Ensure the KEK file permissions are restricted to the current owner.
     *
     * @throws \RuntimeException if KEK file permissions are too permissive
     */
    public static function assertSecureKekPermissions(?string $path = null): void
    {
        $path ??= self::getKekPath();

        clearstatcache(true, $path);

        $permissions = fileperms($path);

        if ($permissions === false) {
            throw new \RuntimeException('Unable to read KEK file permissions at: '.$path);
        }

        $normalizedPermissions = $permissions & self::FILE_PERMISSION_MASK;

        if ($normalizedPermissions !== self::KEK_FILE_PERMISSIONS) {
            throw new \RuntimeException(sprintf(
                'KEK file has insecure permissions: %04o (expected 0600) at: %s',
                $normalizedPermissions,
                $path,
            ));
        }
    }

    /**
     * Ensure the KEK file can be read by the current process.
     *
     * @throws \RuntimeException if the KEK file is not readable
     */
    public static function assertReadableKekFile(?string $path = null): void
    {
        $path ??= self::getKekPath();
        $check = self::$readableChecker ?? \is_readable(...);

        if (! $check($path)) {
            throw new \RuntimeException('KEK file is not readable by this process at: '.$path);
        }
    }

    /**
     * Generate a new KEK and store it securely.
     *
     * Production-safe: refuses to overwrite an existing KEK file. Use
     * {@see self::ensureKekExists()} when an idempotent, race-tolerant
     * "create if missing" is desired (e.g. test factories, seeders).
     *
     * @throws \RuntimeException if the KEK file already exists, the keys
     *                           directory cannot be created, or the file
     *                           cannot be written/secured
     */
    public static function generateKek(): void
    {
        $path = self::getKekPath();

        if (! self::tryCreateKekFile($path)) {
            throw new \RuntimeException('KEK file already exists at: '.$path);
        }
    }

    /**
     * Ensure a KEK file exists, creating it if missing.
     *
     * Race-safe variant of {@see self::generateKek()} suitable for parallel
     * test bootstrapping and seeders. If another process wins the create
     * race, this method silently treats the file as already present without
     * overwriting it.
     *
     * @throws \RuntimeException if the file cannot be created and no valid
     *                           KEK file exists after the attempt
     */
    public static function ensureKekExists(): void
    {
        $path = self::getKekPath();

        clearstatcache(true, $path);

        if (file_exists($path)) {
            return;
        }

        if (self::tryCreateKekFile($path)) {
            return;
        }

        // Another writer won the race; treat any existing file as valid here.
        // The winning writer will either complete successfully or its own error
        // path will unlink the partial file. A size check is intentionally
        // omitted: the winning writer may still be flushing, so checking
        // filesize() here would race against the in-progress write and produce
        // a spurious RuntimeException even when the KEK will be valid moments later.
        clearstatcache(true, $path);

        if (file_exists($path)) {
            return;
        }

        throw new \RuntimeException('Failed to create KEK file at: '.$path);
    }

    /**
     * Attempt to create a KEK file with exclusive semantics.
     *
     * Returns true when this process successfully created the file; returns
     * false when another writer already created it (TOCTOU race). All other
     * failure modes throw RuntimeException so callers can decide whether to
     * surface them.
     *
     * The fopen() warning is suppressed because Laravel converts E_WARNING
     * into ErrorException during tests; under parallel workers this would
     * unwind the stack before we can inspect the false return value.
     */
    private static function tryCreateKekFile(string $path): bool
    {
        $dir = dirname($path);

        if (! self::ensureKeysDirectoryExists($dir)) {
            throw new \RuntimeException('Failed to create keys directory');
        }

        $kek = sodium_crypto_secretbox_keygen();

        $previousUmask = umask(0077);

        $handle = false;

        try {
            $handle = @fopen($path, 'xb');

            if ($handle === false) {
                return false;
            }

            $bytesWritten = fwrite($handle, $kek);

            if ($bytesWritten !== strlen($kek)) {
                fclose($handle);
                $handle = false;
                @unlink($path);

                throw new \RuntimeException('Failed to write KEK file');
            }

            if (! fclose($handle)) {
                $handle = false;
                @unlink($path);

                throw new \RuntimeException('Failed to finalize KEK file');
            }

            $handle = false;
        } finally {
            umask($previousUmask);

            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (! chmod($path, self::KEK_FILE_PERMISSIONS)) {
            @unlink($path);

            throw new \RuntimeException('Failed to set KEK file permissions');
        }

        return true;
    }

    /**
     * Create the keys directory while tolerating parallel test workers racing to create it first.
     */
    private static function ensureKeysDirectoryExists(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        set_error_handler(static fn (): bool => is_dir($dir));

        try {
            if (mkdir($dir, self::KEY_DIRECTORY_PERMISSIONS, true)) {
                return true;
            }
        } finally {
            restore_error_handler();
            clearstatcache(true, $dir);
        }

        return is_dir($dir);
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
        $index = hash_hmac(self::HMAC_ALGORITHM, $plaintext, $idxKey, true);

        sodium_memzero($idxKey);

        return $index;
    }
}
