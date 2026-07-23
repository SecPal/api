<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

return [
    'public_enabled' => filter_var(env('BOOTSTRAP_PUBLIC_ENABLED', false), FILTER_VALIDATE_BOOL),
    'instance_display_name' => env('BOOTSTRAP_INSTANCE_DISPLAY_NAME'),
    'retryable' => filter_var(env('BOOTSTRAP_RETRYABLE', true), FILTER_VALIDATE_BOOL),
    'retry_after_seconds' => (int) env('BOOTSTRAP_RETRY_AFTER_SECONDS', 60),
    'minimum_supported_app_version' => env('BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION'),
    'minimum_supported_app_build' => env('BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD') !== null ? (int) env('BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD') : null,
    'api_release' => [
        'version' => env('BOOTSTRAP_API_RELEASE_VERSION'),
        'source_url' => env('BOOTSTRAP_API_RELEASE_SOURCE_URL'),
    ],
    'legal' => [
        'license_spdx_id' => env('BOOTSTRAP_LICENSE_SPDX_ID', 'AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution'),
        'license_name' => env('BOOTSTRAP_LICENSE_NAME', 'GNU Affero General Public License v3.0 or later with SecPal attribution additional terms'),
        'license_url' => env('BOOTSTRAP_LICENSE_URL', 'https://github.com/SecPal/api/blob/main/LICENSES/LicenseRef-SecPal-Attribution.txt'),
        'license_base_url' => env('BOOTSTRAP_LICENSE_BASE_URL', 'https://www.gnu.org/licenses/agpl-3.0.html'),
        'copyright_notice' => env('BOOTSTRAP_COPYRIGHT_NOTICE', 'Copyright SecPal and contributors.'),
        'warranty_notice' => env(
            'BOOTSTRAP_WARRANTY_NOTICE',
            'This program is distributed without any warranty; without even the implied warranty of merchantability or fitness for a particular purpose.'
        ),
        'source_repositories' => [
            [
                'name' => 'SecPal/frontend',
                'url' => env('BOOTSTRAP_SOURCE_REPOSITORY_FRONTEND_URL', 'https://github.com/SecPal/frontend'),
                'description' => 'React/TypeScript frontend for the running SecPal web application.',
            ],
            [
                'name' => 'SecPal/api',
                'url' => env('BOOTSTRAP_SOURCE_REPOSITORY_API_URL', 'https://github.com/SecPal/api'),
                'description' => 'Laravel backend used by SecPal deployments for API and business logic.',
            ],
            [
                'name' => 'SecPal/contracts',
                'url' => env('BOOTSTRAP_SOURCE_REPOSITORY_CONTRACTS_URL', 'https://github.com/SecPal/contracts'),
                'description' => 'Shared OpenAPI contracts and interface definitions used across SecPal components.',
            ],
        ],
    ],
    'features' => [
        'password_login' => filter_var(env('BOOTSTRAP_PASSWORD_LOGIN_ENABLED', true), FILTER_VALIDATE_BOOL),
        'passkey_login' => filter_var(env('BOOTSTRAP_PASSKEY_LOGIN_ENABLED', true), FILTER_VALIDATE_BOOL),
        'notification_channels' => [
            'android_fcm' => filter_var(env('BOOTSTRAP_ANDROID_PUSH_ENABLED', false), FILTER_VALIDATE_BOOL),
            'web_push' => filter_var(env('BOOTSTRAP_WEB_PUSH_ENABLED', false), FILTER_VALIDATE_BOOL),
        ],
    ],
    'notification_channels' => [
        'android_fcm' => [
            'metadata_revision' => env('BOOTSTRAP_ANDROID_PUSH_METADATA_REVISION'),
            'public_runtime_metadata' => [
                'api_key' => env('BOOTSTRAP_ANDROID_PUSH_PUBLIC_API_KEY'),
                'project_id' => env('BOOTSTRAP_ANDROID_PUSH_PUBLIC_PROJECT_ID'),
                'application_id' => env('BOOTSTRAP_ANDROID_PUSH_PUBLIC_APPLICATION_ID'),
                'sender_id' => env('BOOTSTRAP_ANDROID_PUSH_PUBLIC_SENDER_ID'),
            ],
        ],
        'web_push' => [
            'metadata_revision' => env('BOOTSTRAP_WEB_PUSH_METADATA_REVISION'),
            'public_runtime_metadata' => [
                'vapid_public_key' => env('BOOTSTRAP_WEB_PUSH_PUBLIC_VAPID_KEY'),
            ],
        ],
    ],
];
