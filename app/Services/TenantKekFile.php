<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use SensitiveParameter;

/** Read a key supplied by the external secret authority through a private mount. */
final class TenantKekFile
{
    private const UNAVAILABLE = 'Exact Tenant KEK generation is unavailable.';

    public function load(TenantKekReference $reference, #[SensitiveParameter] string $path): string
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException(self::UNAVAILABLE);
        }

        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false || ! self::safeFile($before)) {
            throw new RuntimeException(self::UNAVAILABLE);
        }

        $stream = @fopen($path, 'rbn');
        if ($stream === false) {
            throw new RuntimeException(self::UNAVAILABLE);
        }

        try {
            $opened = fstat($stream);
            clearstatcache(true, $path);
            $after = @lstat($path);
            if ($opened === false || ! self::safeFile($opened) || $after === false || ! self::safeFile($after)
                || $before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino']
                || $after['dev'] !== $opened['dev'] || $after['ino'] !== $opened['ino']) {
                throw new RuntimeException(self::UNAVAILABLE);
            }

            $key = @stream_get_contents($stream, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES + 1);
            if ($key === false) {
                throw new RuntimeException(self::UNAVAILABLE);
            }
            if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
                || ! hash_equals($reference->fingerprint, hash('sha256', $key))) {
                sodium_memzero($key);

                throw new RuntimeException(self::UNAVAILABLE);
            }

            return $key;
        } finally {
            fclose($stream);
        }
    }

    /** @param array{mode: int, uid: int, size: int} $file */
    private static function safeFile(array $file): bool
    {
        return ($file['mode'] & 0170000) === 0100000
            && in_array($file['mode'] & 07777, [0400, 0600], true)
            && $file['uid'] === posix_geteuid()
            && $file['size'] === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    }
}
