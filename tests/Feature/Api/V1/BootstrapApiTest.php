<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'app.name' => 'SecPal Demo',
        'app.url' => 'https://api.secpal.dev/',
        'bootstrap.public_enabled' => true,
        'bootstrap.retryable' => true,
        'bootstrap.retry_after_seconds' => 60,
        'bootstrap.minimum_supported_app_version' => '1.4.0',
        'bootstrap.minimum_supported_app_build' => 10400,
        'bootstrap.features.password_login' => true,
        'bootstrap.features.passkey_login' => true,
        'bootstrap.features.notification_channels.android_fcm' => true,
        'bootstrap.features.notification_channels.web_push' => false,
        'bootstrap.notification_channels.android_fcm.metadata_revision' => 3,
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.api_key' => 'public-client-api-key-demo-1234567890',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.project_id' => 'secpal-demo-push',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.application_id' => '1:1234567890:android:abcdef1234567890',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.sender_id' => '1234567890',
    ]);
});

test('public bootstrap returns deployment-derived runtime metadata for a supported android client', function (): void {
    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'client_platform' => 'android',
                'api_base_url' => 'https://api.secpal.dev/v1',
                'instance' => [
                    'display_name' => 'SecPal Demo',
                ],
                'compatibility' => [
                    'bootstrap_version' => 'v1',
                    'schema_version' => 4,
                    'minimum_supported_app_version' => '1.4.0',
                    'minimum_supported_app_build' => 10400,
                ],
                'legal' => [
                    'license' => [
                        'spdx_id' => 'AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution',
                        'name' => 'GNU Affero General Public License v3.0 or later with SecPal attribution additional terms',
                        'url' => 'https://github.com/SecPal/api/blob/main/LICENSES/LicenseRef-SecPal-Attribution.txt',
                        'base_license_url' => 'https://www.gnu.org/licenses/agpl-3.0.html',
                    ],
                    'source_url' => 'https://api.secpal.dev/v1/source',
                ],
                'features' => [
                    'password_login' => true,
                    'passkey_login' => true,
                    'notification_channels' => [
                        'android_fcm' => true,
                        'web_push' => false,
                    ],
                ],
                'notification_channels' => [
                    'android_fcm' => [
                        'channel' => 'android_fcm',
                        'metadata_revision' => 3,
                        'public_runtime_metadata' => [
                            'api_key' => 'public-client-api-key-demo-1234567890',
                            'project_id' => 'secpal-demo-push',
                            'application_id' => '1:1234567890:android:abcdef1234567890',
                            'sender_id' => '1234567890',
                        ],
                    ],
                ],
            ],
        ]);
});

test('bootstrap and route registry expose no Android enrollment or provisioning surface', function (): void {
    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertOk()
        ->assertJsonMissingPath('data.features.managed_android_enrollment');

    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder'])->assertSuccessful();

    expect(collect(app('router')->getRoutes()->getRoutes())
        ->pluck('uri')
        ->filter(static fn (string $uri): bool => str_contains($uri, 'android-enrollment') || str_contains($uri, 'android/bootstrap/exchange'))
        ->all())
        ->toBe([])
        ->and(Permission::query()->whereIn('name', [
            'android_enrollment.read',
            'android_enrollment.write',
        ])->exists())->toBeFalse()
        ->and(Schema::hasTable('android_enrollment_sessions'))->toBeFalse();
});

test('public bootstrap returns web push runtime metadata for browser clients without requiring android app version fields', function (): void {
    $vapidPublicKey = 'BE9tfo-aCxwtPk9QYXKDlAUGBwgJCgsMDQ4PEBESExQVobLD1OX2BxgpMEFSY3SFlgcYKTBLXG1-j5ABAgMEBQY';

    config([
        'bootstrap.features.notification_channels.android_fcm' => true,
        'bootstrap.features.notification_channels.web_push' => true,
        'bootstrap.notification_channels.android_fcm.metadata_revision' => 3,
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.api_key' => 'public-client-api-key-demo-1234567890',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.project_id' => 'secpal-demo-push',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.application_id' => '1:1234567890:android:abcdef1234567890',
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.sender_id' => '1234567890',
        'bootstrap.notification_channels.web_push.metadata_revision' => 5,
        'bootstrap.notification_channels.web_push.public_runtime_metadata.vapid_public_key' => $vapidPublicKey,
    ]);

    $response = getJson('/v1/bootstrap?client_platform=browser');

    $response->assertOk()
        ->assertJsonPath('data.client_platform', 'browser')
        ->assertJsonPath('data.compatibility.bootstrap_version', 'v1')
        ->assertJsonPath('data.compatibility.schema_version', 4)
        ->assertJsonPath('data.legal.source_url', 'https://api.secpal.dev/v1/source')
        ->assertJsonPath('data.legal.license.spdx_id', 'AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution')
        ->assertJsonPath('data.legal.license.url', 'https://github.com/SecPal/api/blob/main/LICENSES/LicenseRef-SecPal-Attribution.txt')
        ->assertJsonPath('data.legal.license.base_license_url', 'https://www.gnu.org/licenses/agpl-3.0.html')
        ->assertJsonPath('data.features.notification_channels.android_fcm', true)
        ->assertJsonPath('data.features.notification_channels.web_push', true)
        ->assertJsonPath('data.notification_channels.web_push.channel', 'web_push')
        ->assertJsonPath('data.notification_channels.web_push.metadata_revision', 5)
        ->assertJsonPath('data.notification_channels.web_push.public_runtime_metadata.vapid_public_key', $vapidPublicKey)
        ->assertJsonMissingPath('data.notification_channels.android_fcm');

    expect($response->getContent())->not->toContain('public-client-api-key-demo-1234567890');
});

test('public bootstrap omits notification channel metadata when authenticated installation registration is disabled', function (): void {
    config([
        'bootstrap.features.notification_channels.android_fcm' => false,
    ]);

    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertOk()
        ->assertJsonPath('data.features.notification_channels.android_fcm', false)
        ->assertJsonPath('data.features.notification_channels.web_push', false)
        ->assertJsonMissingPath('data.notification_channels');
});

test('public bootstrap never exposes server side android push credentials', function (): void {
    config([
        'services.fcm.project_id' => 'customer-owned-server-project',
        'services.fcm.client_email' => 'firebase-adminsdk@customer-owned-server-project.iam.gserviceaccount.com',
        'services.fcm.private_key' => "-----BEGIN PRIVATE KEY-----\nserver-only-secret\n-----END PRIVATE KEY-----\n",
    ]);

    $response = getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400');

    $response->assertOk()
        ->assertJsonMissingPath('data.notification_channels.android_fcm.public_runtime_metadata.client_email')
        ->assertJsonMissingPath('data.notification_channels.android_fcm.public_runtime_metadata.private_key');

    expect($response->getContent())
        ->not->toContain('customer-owned-server-project')
        ->not->toContain('firebase-adminsdk@customer-owned-server-project.iam.gserviceaccount.com')
        ->not->toContain('server-only-secret');
});

test('public bootstrap never exposes server side web push delivery credentials', function (): void {
    config([
        'bootstrap.features.notification_channels.android_fcm' => false,
        'bootstrap.features.notification_channels.web_push' => true,
        'bootstrap.notification_channels.web_push.metadata_revision' => 5,
        'bootstrap.notification_channels.web_push.public_runtime_metadata.vapid_public_key' => 'BE9tfo-aCxwtPk9QYXKDlAUGBwgJCgsMDQ4PEBESExQVobLD1OX2BxgpMEFSY3SFlgcYKTBLXG1-j5ABAgMEBQY',
        'services.web_push.subject' => 'mailto:notifications@secpal.dev',
        'services.web_push.private_key' => 'server-only-web-push-secret',
    ]);

    $response = getJson('/v1/bootstrap?client_platform=browser');

    $response->assertOk()
        ->assertJsonMissingPath('data.notification_channels.web_push.public_runtime_metadata.subject')
        ->assertJsonMissingPath('data.notification_channels.web_push.public_runtime_metadata.private_key');

    expect($response->getContent())
        ->not->toContain('notifications@secpal.dev')
        ->not->toContain('server-only-web-push-secret');
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

test('public bootstrap fails closed when app url uses a non-http scheme', function (): void {
    config([
        'app.url' => 'ftp://api.secpal.dev',
    ]);

    getJson('/v1/bootstrap?client_platform=browser')
        ->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Public bootstrap configuration is incomplete for this deployment.',
            'code' => 'BOOTSTRAP_STATE_INVALID',
            'details' => [
                'missing_fields' => [
                    'api_base_url',
                ],
            ],
        ]);
});

test('public bootstrap fails closed when android fcm runtime metadata is enabled but incomplete', function (): void {
    config([
        'bootstrap.notification_channels.android_fcm.metadata_revision' => null,
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.api_key' => null,
    ]);

    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Public bootstrap configuration is incomplete for this deployment.',
            'code' => 'BOOTSTRAP_STATE_INVALID',
            'details' => [
                'missing_fields' => [
                    'notification_channels.android_fcm.metadata_revision',
                    'notification_channels.android_fcm.public_runtime_metadata.api_key',
                ],
            ],
        ]);
});

test('public bootstrap fails closed when web push runtime metadata is enabled but incomplete', function (): void {
    config([
        'bootstrap.features.notification_channels.web_push' => true,
        'bootstrap.notification_channels.web_push.metadata_revision' => 5,
        'bootstrap.notification_channels.web_push.public_runtime_metadata.vapid_public_key' => null,
    ]);

    getJson('/v1/bootstrap?client_platform=browser')
        ->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Public bootstrap configuration is incomplete for this deployment.',
            'code' => 'BOOTSTRAP_STATE_INVALID',
            'details' => [
                'missing_fields' => [
                    'notification_channels.web_push.public_runtime_metadata.vapid_public_key',
                ],
            ],
        ]);
});

test('public bootstrap fails closed when source offer metadata is incomplete', function (): void {
    config([
        'bootstrap.legal.source_repositories' => [
            [
                'name' => 'SecPal/api',
                'url' => '',
                'description' => 'Laravel backend used by SecPal deployments for API and business logic.',
            ],
        ],
    ]);

    getJson('/v1/bootstrap?client_platform=browser')
        ->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Public bootstrap configuration is incomplete for this deployment.',
            'code' => 'BOOTSTRAP_STATE_INVALID',
            'details' => [
                'missing_fields' => [
                    'legal.source_repositories.0.url',
                ],
            ],
        ]);
});

test('public bootstrap for browser clients ignores incomplete android fcm metadata', function (): void {
    config([
        'bootstrap.features.notification_channels.android_fcm' => true,
        'bootstrap.features.notification_channels.web_push' => true,
        'bootstrap.notification_channels.android_fcm.metadata_revision' => null,
        'bootstrap.notification_channels.android_fcm.public_runtime_metadata.api_key' => null,
        'bootstrap.notification_channels.web_push.metadata_revision' => 5,
        'bootstrap.notification_channels.web_push.public_runtime_metadata.vapid_public_key' => 'BE9tfo-aCxwtPk9QYXKDlAUGBwgJCgsMDQ4PEBESExQVobLD1OX2BxgpMEFSY3SFlgcYKTBLXG1-j5ABAgMEBQY',
    ]);

    getJson('/v1/bootstrap?client_platform=browser')
        ->assertOk()
        ->assertJsonPath('data.notification_channels.web_push.channel', 'web_push')
        ->assertJsonMissingPath('data.notification_channels.android_fcm');
});

test('public bootstrap fails closed when android fcm metadata revision is not a strict positive integer', function (): void {
    config([
        'bootstrap.notification_channels.android_fcm.metadata_revision' => '3.5',
    ]);

    getJson('/v1/bootstrap?client_platform=android&app_version=1.4.0&app_build=10400')
        ->assertInternalServerError()
        ->assertExactJson([
            'message' => 'Public bootstrap configuration is incomplete for this deployment.',
            'code' => 'BOOTSTRAP_STATE_INVALID',
            'details' => [
                'missing_fields' => [
                    'notification_channels.android_fcm.metadata_revision (present but invalid; must be a positive integer)',
                ],
            ],
        ]);
});

test('public bootstrap fails closed when APP_URL contains a non-root path prefix', function (): void {
    config(['app.url' => 'https://api.secpal.dev/api']);

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
