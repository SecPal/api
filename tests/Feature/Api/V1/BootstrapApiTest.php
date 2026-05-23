<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'app.name' => 'SecPal Demo',
        'app.url' => 'https://customer-api.example/',
        'bootstrap.public_enabled' => true,
        'bootstrap.retryable' => true,
        'bootstrap.retry_after_seconds' => 60,
        'bootstrap.minimum_supported_app_version' => '1.4.0',
        'bootstrap.minimum_supported_app_build' => 10400,
        'bootstrap.features.password_login' => true,
        'bootstrap.features.passkey_login' => true,
        'bootstrap.features.managed_android_enrollment' => true,
    ]);
});

test('public bootstrap returns deployment-derived runtime metadata for a supported android client', function (): void {
    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'client_platform' => 'android',
                'api_base_url' => 'https://customer-api.example/v1',
                'instance' => [
                    'display_name' => 'SecPal Demo',
                ],
                'compatibility' => [
                    'bootstrap_version' => 'v1',
                    'schema_version' => 1,
                    'minimum_supported_app_version' => '1.4.0',
                    'minimum_supported_app_build' => 10400,
                ],
                'features' => [
                    'password_login' => true,
                    'passkey_login' => true,
                    'managed_android_enrollment' => true,
                ],
            ],
        ]);
});

test('public bootstrap rejects android clients below the configured minimum version', function (): void {
    getJson('/v1/bootstrap?client_platform=android&app_version=1.3.2&app_build=10302')
        ->assertStatus(426)
        ->assertExactJson([
            'message' => 'This SecPal deployment requires app version 1.4.0 (build 10400) or newer before login may proceed.',
            'code' => 'UNSUPPORTED_CLIENT_VERSION',
            'details' => [
                'provided_app_version' => '1.3.2',
                'provided_app_build' => 10302,
                'minimum_supported_app_version' => '1.4.0',
                'minimum_supported_app_build' => 10400,
                'bootstrap_version' => 'v1',
            ],
        ]);
});

test('public bootstrap rejects clients whose app version is below minimum even when the build is newer', function (): void {
    getJson('/v1/bootstrap?client_platform=android&app_version=1.3.9&app_build=99999')
        ->assertStatus(426)
        ->assertExactJson([
            'message' => 'This SecPal deployment requires app version 1.4.0 (build 10400) or newer before login may proceed.',
            'code' => 'UNSUPPORTED_CLIENT_VERSION',
            'details' => [
                'provided_app_version' => '1.3.9',
                'provided_app_build' => 99999,
                'minimum_supported_app_version' => '1.4.0',
                'minimum_supported_app_build' => 10400,
                'bootstrap_version' => 'v1',
            ],
        ]);
});

test('public bootstrap accepts clients whose app version is newer even when the build is lower', function (): void {
    getJson('/v1/bootstrap?client_platform=android&app_version=1.5.0&app_build=1')
        ->assertOk()
        ->assertJsonPath('data.compatibility.minimum_supported_app_version', '1.4.0')
        ->assertJsonPath('data.compatibility.minimum_supported_app_build', 10400);
});

test('public bootstrap fails closed when required bootstrap metadata is missing', function (): void {
    config([
        'app.name' => '',
        'app.url' => '',
        'bootstrap.minimum_supported_app_version' => null,
        'bootstrap.minimum_supported_app_build' => null,
    ]);

    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Public bootstrap configuration is incomplete for this deployment.',
            'code' => 'BOOTSTRAP_STATE_INVALID',
            'details' => [
                'missing_fields' => [
                    'api_base_url',
                    'instance.display_name',
                    'compatibility.minimum_supported_app_version',
                    'compatibility.minimum_supported_app_build',
                ],
            ],
        ]);
});

test('public bootstrap fails closed when APP_URL contains a non-root path prefix', function (): void {
    config(['app.url' => 'https://api.example.com/api']);

    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertInternalServerError()
        ->assertJsonPath('code', 'BOOTSTRAP_STATE_INVALID')
        ->assertJsonPath('details.missing_fields', ['api_base_url']);
});

test('public bootstrap accepts APP_URL already containing the /v1 path without doubling it', function (): void {
    config(['app.url' => 'https://api.secpal.dev/v1']);

    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertOk()
        ->assertJsonPath('data.api_base_url', 'https://api.secpal.dev/v1');
});

test('public bootstrap accepts minimum_supported_app_build when it arrives as a string from env', function (): void {
    config(['bootstrap.minimum_supported_app_build' => '10400']);

    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertOk()
        ->assertJsonPath('data.compatibility.minimum_supported_app_build', 10400);
});

test('public bootstrap rejects app_version that does not match semver format', function (): void {
    getJson('/v1/bootstrap?client_platform=android&app_version=not-a-version&app_build=10400')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['app_version']);
});

test('public bootstrap rate limiting cannot be bypassed by rotating client_platform values', function (): void {
    $server = ['REMOTE_ADDR' => '203.0.113.25'];

    foreach (range(1, 5) as $attempt) {
        $this->call('GET', '/v1/bootstrap', [
            'client_platform' => 'invalid-'.$attempt,
            'app_version' => '1.4.0',
            'app_build' => 10400,
        ], [], [], $server)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_platform']);
    }

    $response = $this->call('GET', '/v1/bootstrap', [
        'client_platform' => 'invalid-6',
        'app_version' => '1.4.0',
        'app_build' => 10400,
    ], [], [], $server);

    $response->assertTooManyRequests();
    expect($response->json('message'))->toContain('Too many bootstrap requests.');
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

test('public bootstrap can report that configuration is temporarily unavailable', function (): void {
    config([
        'bootstrap.public_enabled' => false,
        'bootstrap.retryable' => true,
        'bootstrap.retry_after_seconds' => 120,
    ]);

    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '120')
        ->assertExactJson([
            'message' => 'Public bootstrap configuration is temporarily unavailable.',
            'code' => 'BOOTSTRAP_CONFIG_UNAVAILABLE',
            'details' => [
                'retryable' => true,
                'retry_after_seconds' => 120,
            ],
        ]);
});

test('public bootstrap omits retry hints when configuration is unavailable and not retryable', function (): void {
    config([
        'bootstrap.public_enabled' => false,
        'bootstrap.retryable' => false,
        'bootstrap.retry_after_seconds' => 120,
    ]);

    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertStatus(503)
        ->assertHeaderMissing('Retry-After')
        ->assertExactJson([
            'message' => 'Public bootstrap configuration is temporarily unavailable.',
            'code' => 'BOOTSTRAP_CONFIG_UNAVAILABLE',
            'details' => [
                'retryable' => false,
            ],
        ]);
});

test('public bootstrap fails closed when the public enabled config is missing', function (): void {
    app('config')->offsetUnset('bootstrap.public_enabled');

    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60')
        ->assertExactJson([
            'message' => 'Public bootstrap configuration is temporarily unavailable.',
            'code' => 'BOOTSTRAP_CONFIG_UNAVAILABLE',
            'details' => [
                'retryable' => true,
                'retry_after_seconds' => 60,
            ],
        ]);
});
