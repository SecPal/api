<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use Random\RandomException;
use SensitiveParameter;

/** @internal Application consumers use the GlobalIdentityKeyWrapper contract. */
final readonly class FileGlobalIdentityRootProvider
{
    private const KEY_BYTES = 32;

    private const UNAVAILABLE = 'Global identity root unavailable.';

    private const PUBLICATION_FAILED = 'Global identity root publication failed.';

    public function __construct(#[SensitiveParameter] private string $directory) {}

    public function load(GlobalIdentityKeyReference $root): string
    {
        $this->verifyDirectory();
        $path = $this->path($root);
        clearstatcache(true, $path);
        $entry = @lstat($path);
        // Inspect the directory entry before opening so special files never reach fopen.
        if ($entry === false || ! $this->isSafeFile($entry, self::KEY_BYTES)) {
            throw new GlobalIdentityKeyException(self::UNAVAILABLE);
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new GlobalIdentityKeyException(self::UNAVAILABLE);
        }

        try {
            $opened = @fstat($stream);
            if ($opened === false || ! $this->isSafeFile($opened, self::KEY_BYTES)
                || $entry['dev'] !== $opened['dev'] || $entry['ino'] !== $opened['ino']) {
                throw new GlobalIdentityKeyException(self::UNAVAILABLE);
            }

            $key = @stream_get_contents($stream, self::KEY_BYTES + 1);
            if ($key === false) {
                throw new GlobalIdentityKeyException(self::UNAVAILABLE);
            }
            if (strlen($key) !== self::KEY_BYTES) {
                sodium_memzero($key);

                throw new GlobalIdentityKeyException(self::UNAVAILABLE);
            }

            return $key;
        } finally {
            @fclose($stream);
        }
    }

    public function provision(GlobalIdentityKeyReference $root): void
    {
        $this->verifyDirectory();
        $temporary = @tempnam($this->directory, '.root-');
        if ($temporary === false) {
            throw new GlobalIdentityKeyException(self::PUBLICATION_FAILED);
        }

        try {
            // tempnam may fall back to the system temporary directory.
            if (dirname($temporary) !== $this->directory) {
                throw new GlobalIdentityKeyException(self::PUBLICATION_FAILED);
            }

            $this->writeTemporaryRoot($temporary);
            // On the same filesystem, link publishes the completed file or fails if the name exists.
            if (! @link($temporary, $this->path($root))) {
                throw new GlobalIdentityKeyException(self::PUBLICATION_FAILED);
            }
        } finally {
            if (! @unlink($temporary)) {
                throw new GlobalIdentityKeyException('Global identity root publication cleanup failed.');
            }
        }

        $this->syncPublication();
    }

    private function verifyDirectory(): void
    {
        if (! str_starts_with($this->directory, '/') || str_contains($this->directory, "\0")) {
            throw new GlobalIdentityKeyException(self::UNAVAILABLE);
        }

        clearstatcache(true, $this->directory);
        if (@realpath($this->directory) !== $this->directory) {
            throw new GlobalIdentityKeyException(self::UNAVAILABLE);
        }

        $directory = @lstat($this->directory);
        if ($directory === false || ($directory['mode'] & 0170000) !== 0040000
            || $directory['uid'] !== posix_geteuid()
            || ! in_array($directory['mode'] & 07777, [0500, 0700], true)) {
            throw new GlobalIdentityKeyException(self::UNAVAILABLE);
        }
    }

    /** @param array{mode: int, uid: int, size: int} $entry */
    private function isSafeFile(array $entry, int $size): bool
    {
        return ($entry['mode'] & 0170000) === 0100000
            && $entry['uid'] === posix_geteuid()
            && in_array($entry['mode'] & 07777, [0400, 0600], true)
            && $entry['size'] === $size;
    }

    private function path(GlobalIdentityKeyReference $root): string
    {
        return $this->directory.'/'.$root->id.'.'.$root->version.'.key';
    }

    private function writeTemporaryRoot(#[SensitiveParameter] string $path): void
    {
        $stream = @fopen($path, 'r+b');
        if ($stream === false) {
            throw new GlobalIdentityKeyException(self::PUBLICATION_FAILED);
        }

        try {
            $entry = @fstat($stream);
            if ($entry === false || ! $this->isSafeFile($entry, 0)) {
                throw new GlobalIdentityKeyException(self::PUBLICATION_FAILED);
            }

            $key = random_bytes(self::KEY_BYTES);
            try {
                if (@fwrite($stream, $key) !== self::KEY_BYTES || ! @fflush($stream) || ! @fsync($stream)) {
                    throw new GlobalIdentityKeyException(self::PUBLICATION_FAILED);
                }
            } finally {
                sodium_memzero($key);
            }
        } catch (RandomException) {
            throw new GlobalIdentityKeyException(self::PUBLICATION_FAILED);
        } finally {
            @fclose($stream);
        }
    }

    private function syncPublication(): void
    {
        $directory = @fopen($this->directory, 'rb');
        if ($directory === false) {
            throw new GlobalIdentityKeyException('Global identity root publication durability could not be confirmed.');
        }

        try {
            if (! @fsync($directory)) {
                throw new GlobalIdentityKeyException('Global identity root publication durability could not be confirmed.');
            }
        } finally {
            @fclose($directory);
        }
    }
}
