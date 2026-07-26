<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('bootstrap|127.0.0.1');
    RateLimiter::clear('release|127.0.0.1');
    RateLimiter::clear('source-offer|127.0.0.1');

    config([
        'app.name' => 'SecPal Demo',
        'app.url' => 'https://api.secpal.dev/',
        'bootstrap.public_enabled' => true,
        'bootstrap.minimum_supported_app_version' => '1.4.0',
        'bootstrap.minimum_supported_app_build' => 10400,
        'bootstrap.api_release.version' => 'api-2026-07-03',
        'bootstrap.api_release.source_url' => 'https://github.com/SecPal/api/releases/download/api-2026-07-03/source.tar.gz',
    ]);
});

test('public release endpoint returns the configured api release metadata', function (): void {
    getJson('/v1/release')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'version' => 'api-2026-07-03',
                'source_url' => 'https://github.com/SecPal/api/releases/download/api-2026-07-03/source.tar.gz',
            ],
        ]);
});

test('public release response remains stateless for the configured spa origin', function (): void {
    $response = $this->withHeaders(spaHeaders())
        ->getJson('/v1/release');

    $response->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', spaOrigin())
        ->assertHeader('X-Frame-Options', 'DENY');

    expectNoSetCookieHeaders($response);
});

test('invalid public release state remains stateless for the configured spa origin', function (): void {
    config([
        'bootstrap.api_release.version' => " \n ",
    ]);

    $response = $this->withHeaders(spaHeaders())
        ->getJson('/v1/release');

    $response->assertInternalServerError()
        ->assertJsonPath('code', 'RELEASE_STATE_INVALID');

    expectNoSetCookieHeaders($response);
});

test('public release does not refresh synthetic incoming cookies', function (): void {
    $response = $this->withCredentials()
        ->withCookies([
            (string) config('session.cookie') => 'synthetic-session-cookie',
            SPA_XSRF_COOKIE_NAME => 'synthetic-xsrf-cookie',
        ])->withHeaders(spaHeaders())
        ->getJson('/v1/release');

    $response->assertOk();

    expectNoSetCookieHeaders($response);
});

test('public release endpoint fails closed when the release version is missing', function (): void {
    config([
        'bootstrap.api_release.version' => " \n ",
    ]);

    getJson('/v1/release')
        ->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Public API release metadata is incomplete for this deployment.',
            'code' => 'RELEASE_STATE_INVALID',
            'details' => [
                'missing_fields' => [
                    'api_release.version',
                ],
            ],
        ]);
});

test('public release endpoint fails closed when the immutable source url is invalid', function (): void {
    config([
        'bootstrap.api_release.source_url' => 'javascript:alert(1)',
    ]);

    getJson('/v1/release')
        ->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Public API release metadata is incomplete for this deployment.',
            'code' => 'RELEASE_STATE_INVALID',
            'details' => [
                'missing_fields' => [
                    'api_release.source_url',
                ],
            ],
        ]);
});

test('public release endpoint rate limiting does not consume the bootstrap throttle bucket', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-03 12:00:00 UTC'));

    try {
        foreach (range(1, 5) as $attempt) {
            getJson('/v1/release')
                ->assertOk();
        }

        getJson('/v1/release')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many release metadata requests. Please try again in 300 seconds.');

        getJson('/v1/bootstrap?client_platform=browser')
            ->assertOk();
    } finally {
        Carbon::setTestNow();
    }
});

test('public release endpoint rate limiting does not consume the source offer throttle bucket', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-03 12:05:00 UTC'));

    try {
        foreach (range(1, 5) as $attempt) {
            getJson('/v1/release')
                ->assertOk();
        }

        getJson('/v1/release')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many release metadata requests. Please try again in 300 seconds.');

        getJson('/v1/source')
            ->assertOk();
    } finally {
        Carbon::setTestNow();
    }
});
