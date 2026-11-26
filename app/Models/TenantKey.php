<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Models;

use App\Exceptions\CorruptedEncryptedAttributeException;
use App\Exceptions\TenantKeyDecryptionException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SensitiveParameter;
use SodiumException;

/**
 * TenantKey model for managing per-tenant envelope encryption keys.
 *
 * This model stores wrapped Data Encryption Keys (DEK) and Index Keys (idx_key)
 * using envelope encryption with a Key Encryption Key (KEK).
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $dek_wrapped BYTEA wrapped DEK
 * @property string $dek_nonce BYTEA nonce for DEK
 * @property string $idx_wrapped BYTEA wrapped idx_key
 * @property string $idx_nonce BYTEA nonce for idx_key
 * @property int $key_version Key version for rotation
 * @property \Illuminate\Support\Carbon $created_at
 */
class TenantKey extends Model
{
    /** @use HasFactory<\Database\Factories\TenantKeyFactory> */
    use HasFactory, HasUuids;

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

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function forTenant(mixed $tenantId): self
    {
        $id = Tenant::parseId($tenantId);
        if ($id === null) {
            throw (new ModelNotFoundException)->setModel(self::class);
        }

        return self::query()->where('tenant_id', $id)->firstOrFail();
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
     * Verify that a KEK file at the given path is complete, readable, and
     * has secure permissions. Used by {@see self::ensureKekExists()} to honor
     * its "any file at the canonical path is a valid KEK" guarantee even on
     * the early-return paths, where the file may have been created by an
     * earlier run, a manually-placed file, or a race winner.
     *
     * @throws \RuntimeException if permissions, readability, or size do not
     *                           match a freshly published KEK
     */
    private static function assertPublishedKekFile(string $path): void
    {
        self::assertSecureKekPermissions($path);
        self::assertReadableKekFile($path);

        clearstatcache(true, $path);
        $size = filesize($path);

        if ($size !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(sprintf(
                'KEK file has invalid size: %s bytes (expected %d) at: %s',
                $size === false ? 'unknown' : (string) $size,
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                $path,
            ));
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

        // tryCreateKekFile() only returns false when the canonical path is
        // already published (atomic link() lost the race). Any other failure
        // surfaces as a RuntimeException from inside tryCreateKekFile() with
        // the underlying PHP error attached.
        if (! self::tryCreateKekFile($path)) {
            throw new \RuntimeException('KEK file already exists at: '.$path);
        }
    }

    /**
     * Ensure a KEK file exists, creating it if missing.
     *
     * Race-safe variant of {@see self::generateKek()} suitable for parallel
     * test bootstrapping and seeders. If another process wins the create
     * race, this method silently treats the published file as canonical
     * without overwriting it.
     *
     * The underlying writer uses an atomic temp-write + hard-link publish,
     * so once the canonical path exists it is GUARANTEED to be a complete,
     * `SODIUM_CRYPTO_SECRETBOX_KEYBYTES`-byte KEK with `0600` permissions —
     * concurrent readers never observe a partial or zero-length file.
     *
     * @throws \RuntimeException if the file cannot be created and no valid
     *                           KEK file exists after the attempt
     */
    public static function ensureKekExists(): void
    {
        $path = self::getKekPath();

        clearstatcache(true, $path);

        if (file_exists($path)) {
            // The early-return path can land on a file we did not create
            // (a leftover from an earlier run, a manually-placed file, or
            // even a race winner's file). Validating permissions, readability
            // and size here makes the docblock guarantee true regardless of
            // who produced the file, and surfaces tampering early instead of
            // letting loadKek() fail later with a generic "Invalid KEK file".
            self::assertPublishedKekFile($path);

            return;
        }

        if (self::tryCreateKekFile($path)) {
            // Just published by us with chmod 0600 and the correct byte count.
            return;
        }

        // Race lost: tryCreateKekFile() only returns false after confirming
        // the canonical path now exists. Because publication is atomic the
        // file the next reader will see is fully written. Validate it here
        // for the same reason as the initial early-return branch.
        clearstatcache(true, $path);

        if (! file_exists($path)) {
            throw new \RuntimeException('Failed to create KEK file at: '.$path);
        }

        self::assertPublishedKekFile($path);
    }

    /**
     * Attempt to publish a KEK file with atomic, race-safe semantics.
     *
     * Strategy: write a fully-formed, chmod'd KEK to a unique temp file in
     * the same directory, then `link()` the temp into the canonical path.
     * On POSIX filesystems `link()` is atomic and fails with EEXIST when
     * the target already exists, which is exactly the race-lost signal we
     * want. Concurrent readers either observe a complete KEK or no file at
     * all — never a partial or zero-length one.
     *
     * Returns true when this process published the canonical file; returns
     * false when another writer's published file is already in place. All
     * other failure modes (write errors, permission denied, missing
     * directory, etc.) raise RuntimeException with the underlying PHP error
     * detail attached so operators can diagnose the failure.
     *
     * The fopen() / link() warnings are suppressed because Laravel converts
     * E_WARNING into ErrorException during tests; under parallel workers
     * this would unwind the stack before we can inspect the false return
     * value.
     */
    private static function tryCreateKekFile(string $path): bool
    {
        $dir = dirname($path);

        if (! self::ensureKeysDirectoryExists($dir)) {
            throw new \RuntimeException('Failed to create keys directory');
        }

        $tempPath = $dir.'/.kek-tmp-'.bin2hex(random_bytes(16));

        $kek = sodium_crypto_secretbox_keygen();
        $previousUmask = umask(0077);
        $handle = false;

        try {
            $handle = @fopen($tempPath, 'xb');

            if ($handle === false) {
                // 32 crypto-random hex chars — a collision is a real failure,
                // not a race we want to swallow.
                throw new \RuntimeException(self::describeError(
                    'Failed to create temporary KEK file at: '.$tempPath,
                ));
            }

            $bytesWritten = fwrite($handle, $kek);

            if ($bytesWritten !== strlen($kek)) {
                fclose($handle);
                $handle = false;

                throw new \RuntimeException('Failed to write temporary KEK file');
            }

            if (! fclose($handle)) {
                $handle = false;

                throw new \RuntimeException('Failed to finalize temporary KEK file');
            }

            $handle = false;

            if (! chmod($tempPath, self::KEK_FILE_PERMISSIONS)) {
                throw new \RuntimeException('Failed to set KEK file permissions');
            }

            if (@link($tempPath, $path)) {
                return true;
            }

            // link() failed. Distinguish the expected race-lost case (target
            // already published by another writer — link() raises EEXIST and
            // file_exists() now reports the canonical path) from real errors
            // such as permission denied, missing parent, or read-only FS.
            clearstatcache(true, $path);

            if (file_exists($path)) {
                return false;
            }

            throw new \RuntimeException(self::describeError(
                'Failed to publish KEK file at: '.$path,
            ));
        } finally {
            umask($previousUmask);

            if (is_resource($handle)) {
                fclose($handle);
            }

            if (is_file($tempPath)) {
                @unlink($tempPath);
            }

            sodium_memzero($kek);
        }
    }

    /**
     * Build a RuntimeException message that includes the most recent PHP
     * error detail when available so operators can diagnose link/fopen
     * failures without re-reading raw warnings.
     */
    private static function describeError(string $context): string
    {
        $error = error_get_last();
        $detail = is_array($error) ? trim($error['message']) : '';

        return $detail !== '' ? $context.' ('.$detail.')' : $context;
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

    /** @return array{dek_wrapped: string, dek_nonce: string, idx_wrapped: string, idx_nonce: string} */
    public static function generateEnvelopeKeys(int $tenantId): array
    {
        $kek = self::loadKek();
        $dek = sodium_crypto_secretbox_keygen();
        $indexKey = sodium_crypto_secretbox_keygen();

        try {
            return self::wrapKey($tenantId, 'dek', $dek, $kek)
                + self::wrapKey($tenantId, 'idx', $indexKey, $kek);
        } finally {
            sodium_memzero($kek);
            sodium_memzero($dek);
            sodium_memzero($indexKey);
        }
    }

    public function unwrapDek(): string
    {
        return $this->unwrapKey('dek');
    }

    public function unwrapIdxKey(): string
    {
        return $this->unwrapKey('idx');
    }

    public function replaceDek(#[SensitiveParameter] string $dek): void
    {
        $kek = self::loadKek();
        try {
            $this->update(self::wrapKey($this->tenant_id, 'dek', $dek, $kek) + [
                'key_version' => $this->key_version + 1,
            ]);
        } finally {
            sodium_memzero($kek);
        }
    }

    /** @return array{dek_wrapped: string, dek_nonce: string, idx_wrapped: string, idx_nonce: string} */
    public function rewrappedEnvelopes(#[SensitiveParameter] string $oldKek, #[SensitiveParameter] string $newKek): array
    {
        $dek = $this->unwrapKey('dek', $oldKek);
        try {
            $indexKey = $this->unwrapKey('idx', $oldKek);
            try {
                return self::wrapKey($this->tenant_id, 'dek', $dek, $newKek)
                    + self::wrapKey($this->tenant_id, 'idx', $indexKey, $newKek);
            } finally {
                sodium_memzero($indexKey);
            }
        } finally {
            sodium_memzero($dek);
        }
    }

    /**
     * @template TPurpose of 'dek'|'idx'
     *
     * @param  TPurpose  $purpose
     * @return (TPurpose is 'dek' ? array{dek_wrapped: string, dek_nonce: string} : array{idx_wrapped: string, idx_nonce: string})
     */
    private static function wrapKey(
        int $tenantId,
        string $purpose,
        #[SensitiveParameter] string $key,
        #[SensitiveParameter] string $kek,
    ): array {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($key, self::keyContext($tenantId, $purpose), $nonce, $kek);

        return $purpose === 'dek'
            ? ['dek_wrapped' => $wrapped, 'dek_nonce' => $nonce]
            : ['idx_wrapped' => $wrapped, 'idx_nonce' => $nonce];
    }

    /** @param 'dek'|'idx' $purpose */
    private function unwrapKey(string $purpose, #[SensitiveParameter] ?string $kek = null): string
    {
        $kek ??= self::loadKek();
        try {
            [$wrapped, $nonce] = $purpose === 'dek'
                ? [$this->dek_wrapped, $this->dek_nonce]
                : [$this->idx_wrapped, $this->idx_nonce];
            $key = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $wrapped,
                self::keyContext($this->tenant_id, $purpose),
                $nonce,
                $kek,
            );
        } catch (SodiumException) {
            throw new CorruptedEncryptedAttributeException('Invalid tenant key envelope');
        } finally {
            sodium_memzero($kek);
        }

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new CorruptedEncryptedAttributeException('Invalid tenant key envelope');
        }

        return $key;
    }

    private static function keyContext(int $tenantId, string $purpose): string
    {
        return "secpal/tenant-key/v1/{$purpose}/{$tenantId}";
    }

    /**
     * Encrypt plaintext data using the tenant's DEK.
     *
     * @return array{ciphertext: string, nonce: string}
     *
     * @throws \RuntimeException if encryption fails
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): array
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
     * @throws TenantKeyDecryptionException if ciphertext authentication fails
     * @throws \RuntimeException if tenant key material cannot be loaded
     */
    public function decrypt(string $ciphertext, string $nonce): string
    {
        $dek = $this->unwrapDek();

        try {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $dek);
        } catch (SodiumException $exception) {
            throw new TenantKeyDecryptionException('Failed to decrypt data', previous: $exception);
        } finally {
            sodium_memzero($dek);
        }

        if ($plaintext === false) {
            throw new TenantKeyDecryptionException('Failed to decrypt data');
        }

        return $plaintext;
    }

    /**
     * Generate a blind index for searchable encrypted fields.
     *
     * Uses HMAC-SHA256 with the tenant's index key.
     */
    public function generateBlindIndex(#[SensitiveParameter] string $plaintext): string
    {
        $idxKey = $this->unwrapIdxKey();
        $index = hash_hmac(self::HMAC_ALGORITHM, $plaintext, $idxKey, true);

        sodium_memzero($idxKey);

        return $index;
    }
}
