<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Support\PublicSourceOffer;

beforeEach(function (): void {
    config([
        'bootstrap.legal.license_spdx_id' => 'AGPL-3.0-or-later',
        'bootstrap.legal.license_name' => 'GNU Affero General Public License v3.0 or later',
        'bootstrap.legal.license_url' => 'https://www.gnu.org/licenses/agpl-3.0.html',
        'bootstrap.legal.copyright_notice' => 'Copyright SecPal and contributors.',
        'bootstrap.legal.warranty_notice' => 'This program is distributed without any warranty; without even the implied warranty of merchantability or fitness for a particular purpose.',
        'bootstrap.legal.source_repositories' => [
            [
                'name' => 'SecPal/api',
                'url' => 'https://github.com/SecPal/api',
                'description' => 'Laravel backend used by SecPal deployments for API and business logic.',
            ],
        ],
    ]);
});

test('canonical source url rejects invalid public app urls', function (?string $appUrl): void {
    config(['app.url' => $appUrl]);

    expect(app(PublicSourceOffer::class)->canonicalSourceUrl())->toBeNull();
})->with([
    'missing app url' => [null],
    'localhost default' => ['http://localhost'],
    'localhost host' => ['https://localhost'],
    'url with credentials' => ['https://user:secret@api.secpal.dev'],
    'url with query string' => ['https://api.secpal.dev?foo=bar'],
    'url with fragment' => ['https://api.secpal.dev#fragment'],
    'url with ftp scheme' => ['ftp://api.secpal.dev'],
    'url with non-root path prefix' => ['https://api.secpal.dev/api'],
]);

test('canonical source url normalizes valid public app urls', function (string $appUrl, string $expected): void {
    config(['app.url' => $appUrl]);

    expect(app(PublicSourceOffer::class)->canonicalSourceUrl())->toBe($expected);
})->with([
    'root url' => ['https://API.SECPAL.DEV/', 'https://API.SECPAL.DEV/v1/source'],
    'v1 url' => ['https://api.secpal.dev/v1', 'https://api.secpal.dev/v1/source'],
    'custom port' => ['https://api.secpal.dev:8443', 'https://api.secpal.dev:8443/v1/source'],
]);

test('missing fields report invalid repository structures and trimmed empty values', function (): void {
    config([
        'bootstrap.legal.license_url' => 'not-a-url',
        'bootstrap.legal.source_repositories' => [
            'invalid-entry',
            [
                'name' => ' ',
                'url' => 'javascript:alert(1)',
                'description' => " \n ",
            ],
        ],
    ]);

    expect(app(PublicSourceOffer::class)->missingFields())->toBe([
        'legal.license.url',
        'legal.source_repositories.0',
        'legal.source_repositories.1.name',
        'legal.source_repositories.1.url',
        'legal.source_repositories.1.description',
    ]);
});
