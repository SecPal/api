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
