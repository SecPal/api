<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Storage;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Laravel/Flysystem adapter seam. No overwrite, list, credential discovery or
 * physical-delete authority is exposed to API/Worker consumers.
 *
 * S3 namespace durability, conditional-write policy, IAM and recovery are
 * independently qualified by deployment#99; a driver name is not qualification.
 */
class ImmutablePrivateObjectBackend
{
    public function disk(): FilesystemAdapter
    {
        if (! in_array(config('private_objects.purpose'), ['api', 'worker'], true)) {
            throw new RuntimeException('Private Object runtime purpose is not authorized');
        }
        $mode = config('private_objects.mode');
        if (! in_array($mode, ['self-hosted', 'managed'], true)) {
            throw new RuntimeException('Private Object backend is not configured');
        }
        $disk = Storage::disk('private_objects');
        if ($mode === 'managed' && ! $disk instanceof AwsS3V3Adapter) {
            throw new RuntimeException('Managed Private Objects require an off-host Object backend');
        }
        if (! $disk instanceof AwsS3V3Adapter && ! $disk instanceof LocalFilesystemAdapter) {
            throw new RuntimeException('Private Object adapter has no qualified create-only primitive');
        }

        if ($disk instanceof AwsS3V3Adapter) {
            $config = $disk->getConfig();
            $endpoint = $config['endpoint'] ?? null;
            if ($endpoint !== null && ! is_string($endpoint)) {
                throw new RuntimeException('Private Object transport is invalid');
            }
            if (($config['root'] ?? '') !== ''
                || ($endpoint !== null && (parse_url($endpoint, PHP_URL_SCHEME) !== 'https'
                    || in_array(strtolower(trim(rtrim((string) parse_url($endpoint, PHP_URL_HOST), '.'), '[]')), ['localhost', '127.0.0.1', '::1', '0.0.0.0', '::'], true)
                    || parse_url($endpoint, PHP_URL_USER) !== null
                    || parse_url($endpoint, PHP_URL_QUERY) !== null
                    || parse_url($endpoint, PHP_URL_FRAGMENT) !== null))
                || ($config['credentials'] ?? null) !== [PrivateObjectCredentials::class, 'load']) {
                throw new RuntimeException('Private Object transport or credential authority is invalid');
            }
        }

        if ($disk instanceof LocalFilesystemAdapter) {
            $this->localRoot($disk);
        }

        return $disk;
    }

    public function identity(): string
    {
        $disk = $this->disk();
        $config = $disk->getConfig();
        $identity = $disk instanceof AwsS3V3Adapter
            ? ['driver' => 's3', 'bucket' => $config['bucket'], 'endpoint' => $config['endpoint'] ?? null, 'region' => $config['region']]
            : ['driver' => 'local', 'root' => rtrim($disk->path(''), DIRECTORY_SEPARATOR)];

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    public function create(string $key, string $bytes): void
    {
        $this->key($key);
        $disk = $this->disk();
        if ($disk instanceof AwsS3V3Adapter) {
            // Ordinary Flysystem put() can overwrite. Use its official SDK
            // conditional operation, never ETag as ciphertext integrity.
            $bucket = $disk->getConfig()['bucket'] ?? null;
            if (! is_string($bucket) || $bucket === '') {
                throw new RuntimeException('Private Object namespace is unavailable');
            }
            $disk->getClient()->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $bytes,
                'IfNoneMatch' => '*',
                'ContentLength' => strlen($bytes),
                'ChecksumSHA256' => base64_encode(hash('sha256', $bytes, true)),
            ]);

            return;
        }
        $root = $this->localRoot($disk);
        $temporary = tempnam($root, '.object-staging-');
        if ($temporary === false || dirname($temporary).DIRECTORY_SEPARATOR !== rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) {
            throw new RuntimeException('Private Object staging failed');
        }
        try {
            chmod($temporary, 0600);
            $stream = fopen($temporary, 'r+b');
            if ($stream === false) {
                throw new RuntimeException('Private Object staging failed');
            }
            try {
                $written = 0;
                while ($written < strlen($bytes)) {
                    $count = fwrite($stream, substr($bytes, $written));
                    if ($count === false || $count === 0) {
                        throw new RuntimeException('Private Object write failed');
                    }
                    $written += $count;
                }
                if (! fflush($stream) || ! fsync($stream)) {
                    throw new RuntimeException('Private Object durability failed');
                }
            } finally {
                fclose($stream);
            }
            if (! @link($temporary, $disk->path($key))) {
                throw new RuntimeException('Private Object collision or uncertain publication');
            }
            $directory = fopen($root, 'r');
            if ($directory === false) {
                throw new RuntimeException('Private Object directory observation failed');
            }
            try {
                if (! fsync($directory)) {
                    throw new RuntimeException('Private Object directory durability failed');
                }
            } finally {
                fclose($directory);
            }
        } finally {
            // Only our private staging name. An uncertain final Object remains.
            @unlink($temporary);
        }
    }

    public function read(string $key, int $expectedBytes): string
    {
        $this->key($key);
        $disk = $this->disk();
        if ($expectedBytes < 1) {
            throw new RuntimeException('Private Object size verification failed');
        }
        if ($disk instanceof LocalFilesystemAdapter) {
            $path = $disk->path($key);
            clearstatcache(true, $path);
            $metadata = @lstat($path);
            if ($metadata === false || ($metadata['mode'] & 0170000) !== 0100000
                || ($metadata['mode'] & 0777) !== 0600 || $metadata['uid'] !== posix_geteuid()
                || $metadata['nlink'] !== 1 || $metadata['size'] !== $expectedBytes) {
                throw new RuntimeException('Private Object file identity is invalid');
            }
            $stream = @fopen($path, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('Private Object read failed');
            }
            $opened = fstat($stream);
            if ($opened === false || $opened['dev'] !== $metadata['dev'] || $opened['ino'] !== $metadata['ino']
                || $opened['size'] !== $expectedBytes) {
                fclose($stream);
                throw new RuntimeException('Private Object file identity changed');
            }
        } else {
            if ($disk->size($key) !== $expectedBytes) {
                throw new RuntimeException('Private Object size verification failed');
            }
            $stream = $disk->readStream($key);
            if (! is_resource($stream)) {
                throw new RuntimeException('Private Object read failed');
            }
        }
        try {
            $bytes = stream_get_contents($stream, $expectedBytes + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($bytes) || strlen($bytes) !== $expectedBytes) {
            throw new RuntimeException('Private Object size verification failed');
        }

        return $bytes;
    }

    private function localRoot(FilesystemAdapter $disk): string
    {
        $root = rtrim($disk->path(''), DIRECTORY_SEPARATOR);
        clearstatcache(true, $root);
        $metadata = @lstat($root);
        if ($metadata === false || ($metadata['mode'] & 0170000) !== 0040000
            || ($metadata['mode'] & 0777) !== 0700 || $metadata['uid'] !== posix_geteuid()
            || realpath($root) !== $root) {
            throw new RuntimeException('Private Object directory is unavailable');
        }

        return $root;
    }

    private function key(string $key): void
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $key) !== 1) {
            throw new RuntimeException('Private Object key is not opaque and canonical');
        }
    }
}
