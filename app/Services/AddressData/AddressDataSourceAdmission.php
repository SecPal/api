<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services\AddressData;

use RuntimeException;

final class AddressDataSourceAdmission
{
    public function expectedSha256(?string $expectedSha256): string
    {
        if ($expectedSha256 === null || preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1) {
            throw new RuntimeException(
                'The expected address-data SHA-256 must contain exactly 64 lowercase hexadecimal characters.',
            );
        }

        return $expectedSha256;
    }

    public function remoteSourceUrl(?string $sourceUrl): string
    {
        if ($sourceUrl === null || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            throw $this->immutableRemoteSourceException();
        }

        $parts = parse_url($sourceUrl);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'raw.githubusercontent.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw $this->immutableRemoteSourceException();
        }

        $path = $parts['path'] ?? null;
        if (! is_string($path)
            || preg_match('/\A\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\/[a-f0-9]{40}\/.+\z/D', $path) !== 1) {
            throw $this->immutableRemoteSourceException();
        }

        return $sourceUrl;
    }

    public function assertMatches(string $expectedSha256, string $actualSha256): void
    {
        if (! hash_equals($expectedSha256, $actualSha256)) {
            throw new RuntimeException(
                "Address data source does not match the expected SHA-256 {$expectedSha256}.",
            );
        }
    }

    private function immutableRemoteSourceException(): RuntimeException
    {
        return new RuntimeException(
            'The remote address-data source must be an immutable commit-pinned GitHub raw URL.',
        );
    }
}
