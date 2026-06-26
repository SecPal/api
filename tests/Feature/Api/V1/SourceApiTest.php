<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('bootstrap|127.0.0.1');
    RateLimiter::clear('source-offer|127.0.0.1');

    config([
        'app.name' => 'SecPal Demo',
        'app.url' => 'https://api.secpal.dev/',
        'bootstrap.public_enabled' => true,
        'bootstrap.minimum_supported_app_version' => '1.4.0',
        'bootstrap.minimum_supported_app_build' => 10400,
        'bootstrap.legal.license_spdx_id' => 'AGPL-3.0-or-later',
        'bootstrap.legal.license_name' => 'GNU Affero General Public License v3.0 or later',
        'bootstrap.legal.license_url' => 'https://www.gnu.org/licenses/agpl-3.0.html',
        'bootstrap.legal.copyright_notice' => 'Copyright SecPal and contributors.',
        'bootstrap.legal.warranty_notice' => 'This program is distributed without any warranty; without even the implied warranty of merchantability or fitness for a particular purpose.',
        'bootstrap.legal.source_repositories' => [
            [
                'name' => 'SecPal/frontend',
                'url' => 'https://github.com/SecPal/frontend',
                'description' => 'React/TypeScript frontend for the running SecPal web application.',
            ],
            [
                'name' => 'SecPal/api',
                'url' => 'https://github.com/SecPal/api',
                'description' => 'Laravel backend used by SecPal deployments for API and business logic.',
            ],
            [
                'name' => 'SecPal/contracts',
                'url' => 'https://github.com/SecPal/contracts',
                'description' => 'Shared OpenAPI contracts and interface definitions used across SecPal components.',
            ],
        ],
    ]);
});

test('public source endpoint returns license and corresponding source metadata', function (): void {
    getJson('/v1/source')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'source_url' => 'https://api.secpal.dev/v1/source',
                'notice' => 'Source offer for users interacting with SecPal over a network.',
                'source_offer' => 'Corresponding source for the SecPal components made available through this service.',
                'license' => [
                    'spdx_id' => 'AGPL-3.0-or-later',
                    'name' => 'GNU Affero General Public License v3.0 or later',
                    'url' => 'https://www.gnu.org/licenses/agpl-3.0.html',
                ],
                'repositories' => [
                    [
                        'name' => 'SecPal/frontend',
                        'url' => 'https://github.com/SecPal/frontend',
                        'description' => 'React/TypeScript frontend for the running SecPal web application.',
                    ],
                    [
                        'name' => 'SecPal/api',
                        'url' => 'https://github.com/SecPal/api',
                        'description' => 'Laravel backend used by SecPal deployments for API and business logic.',
                    ],
                    [
                        'name' => 'SecPal/contracts',
                        'url' => 'https://github.com/SecPal/contracts',
                        'description' => 'Shared OpenAPI contracts and interface definitions used across SecPal components.',
                    ],
                ],
                'copyright_notice' => 'Copyright SecPal and contributors.',
                'warranty_notice' => 'This program is distributed without any warranty; without even the implied warranty of merchantability or fitness for a particular purpose.',
            ],
        ]);
});

test('public source endpoint is reachable without authentication and contains the expected source offer references', function (): void {
    getJson('/v1/source')
        ->assertOk()
        ->assertJsonPath('data.license.spdx_id', 'AGPL-3.0-or-later')
        ->assertJsonPath('data.license.url', 'https://www.gnu.org/licenses/agpl-3.0.html')
        ->assertJsonPath('data.repositories.0.url', 'https://github.com/SecPal/frontend')
        ->assertJsonPath('data.repositories.1.url', 'https://github.com/SecPal/api')
        ->assertJsonPath('data.repositories.2.url', 'https://github.com/SecPal/contracts')
        ->assertJsonPath('data.warranty_notice', 'This program is distributed without any warranty; without even the implied warranty of merchantability or fitness for a particular purpose.');
});

test('public source endpoint fails closed when app url is not configured', function (): void {
    config(['app.url' => 'http://localhost']);

    getJson('/v1/source')
        ->assertInternalServerError()
        ->assertJsonPath('code', 'SOURCE_STATE_INVALID')
        ->assertJsonPath('details.missing_fields.0', 'app_url');
});

test('public source endpoint fails closed when source repository metadata is incomplete', function (): void {
    config([
        'bootstrap.legal.source_repositories' => [
            [
                'name' => 'SecPal/frontend',
                'url' => '',
                'description' => 'React/TypeScript frontend for the running SecPal web application.',
            ],
        ],
    ]);

    getJson('/v1/source')
        ->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Public source offer configuration is incomplete for this deployment.',
            'code' => 'SOURCE_STATE_INVALID',
            'details' => [
                'missing_fields' => [
                    'legal.source_repositories.0.url',
                ],
            ],
        ]);
});

test('public source endpoint rate limiting does not consume the bootstrap throttle bucket', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00 UTC'));

    try {
        foreach (range(1, 5) as $attempt) {
            getJson('/v1/source')
                ->assertOk();
        }

        getJson('/v1/source')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many source offer requests. Please try again in 300 seconds.');

        getJson('/v1/bootstrap?client_platform=browser')
            ->assertOk()
            ->assertJsonPath('data.legal.source_url', 'https://api.secpal.dev/v1/source');
    } finally {
        Carbon::setTestNow();
    }
});
