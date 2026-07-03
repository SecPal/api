<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Support\PublicApiRelease;

beforeEach(function (): void {
    config([
        'bootstrap.api_release.version' => 'api-2026-07-03',
        'bootstrap.api_release.source_url' => 'https://github.com/SecPal/api/releases/download/api-2026-07-03/source.tar.gz',
    ]);
});

test('release response data trims the configured version before returning it', function (): void {
    config([
        'bootstrap.api_release.version' => ' api-2026-07-03 ',
    ]);

    expect(app(PublicApiRelease::class)->responseData())->toBe([
        'version' => 'api-2026-07-03',
        'source_url' => 'https://github.com/SecPal/api/releases/download/api-2026-07-03/source.tar.gz',
    ]);
});

test('missing fields report invalid release metadata', function (): void {
    config([
        'bootstrap.api_release.version' => '',
        'bootstrap.api_release.source_url' => 'ftp://localhost/source.tar.gz',
    ]);

    expect(app(PublicApiRelease::class)->missingFields())->toBe([
        'api_release.version',
        'api_release.source_url',
    ]);
});
