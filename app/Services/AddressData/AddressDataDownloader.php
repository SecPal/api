<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services\AddressData;

use App\Support\AddressDataConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class AddressDataDownloader
{
    /**
     * @param  (callable(string): void)|null  $onProgress
     * @return array{path: string, sha256: string, etag: ?string, last_modified: ?string}
     */
    public function download(string $sourceUrl, ?string $forceFromPath = null, ?callable $onProgress = null): array
    {
        $emit = static function (string $message) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($message);
            }
        };

        if ($forceFromPath !== null && $forceFromPath !== '') {
            if (! is_readable($forceFromPath)) {
                throw new RuntimeException("Address data source file is not readable: {$forceFromPath}");
            }

            $emit('Using local CSV: '.$forceFromPath);

            $sha256 = hash_file('sha256', $forceFromPath);
            if ($sha256 === false) {
                throw new RuntimeException('Could not hash address data file.');
            }

            return [
                'path' => $forceFromPath,
                'sha256' => $sha256,
                'etag' => null,
                'last_modified' => null,
            ];
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory('address-data/tmp');

        $tempRelative = 'address-data/tmp/'.uniqid('streets_', true).'.csv';
        $fullPath = $disk->path($tempRelative);

        $timeout = AddressDataConfig::int('address_data.download_timeout', 600);

        $emit('Downloading address CSV (large file; no byte-level progress until complete — typically several minutes)…');
        $emit('URL: '.$sourceUrl);

        $etag = null;
        $lastModified = null;

        try {
            $emit('Checking HTTP headers (HEAD)…');
            $headResponse = Http::timeout(min(30, $timeout))
                ->withOptions(['connect_timeout' => 30])
                ->head($sourceUrl);

            if ($headResponse->successful()) {
                $etag = $headResponse->header('ETag');
                $lastModified = $headResponse->header('Last-Modified');
            }
        } catch (\Throwable) {
            // HEAD is optional; continue with GET.
        }

        $emit('Starting HTTP download (streaming to disk)…');

        $response = Http::timeout($timeout)
            ->connectTimeout(30)
            ->sink($fullPath)
            ->get($sourceUrl);

        if (! $response->successful()) {
            @unlink($fullPath);
            throw new RuntimeException(
                'Address data download failed with HTTP '.$response->status().' for '.$sourceUrl,
            );
        }

        $bytes = @filesize($fullPath);
        $sizeLabel = is_int($bytes) ? number_format($bytes).' bytes' : 'unknown size';
        $emit('Download finished ('.$sizeLabel.'), computing SHA-256…');

        $sha256 = hash_file('sha256', $fullPath);
        if ($sha256 === false) {
            throw new RuntimeException('Could not hash downloaded address data file.');
        }

        $emit('Checksum: '.$sha256);

        return [
            'path' => $fullPath,
            'sha256' => $sha256,
            'etag' => is_string($etag) && $etag !== '' ? $etag : null,
            'last_modified' => is_string($lastModified) && $lastModified !== '' ? $lastModified : null,
        ];
    }
}
