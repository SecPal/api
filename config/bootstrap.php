<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'public_enabled' => filter_var(env('BOOTSTRAP_PUBLIC_ENABLED', false), FILTER_VALIDATE_BOOL),
    'instance_display_name' => env('BOOTSTRAP_INSTANCE_DISPLAY_NAME'),
    'retryable' => filter_var(env('BOOTSTRAP_RETRYABLE', true), FILTER_VALIDATE_BOOL),
    'retry_after_seconds' => (int) env('BOOTSTRAP_RETRY_AFTER_SECONDS', 60),
    'minimum_supported_app_version' => env('BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION'),
    'minimum_supported_app_build' => env('BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD') !== null ? (int) env('BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD') : null,
    'features' => [
        'password_login' => filter_var(env('BOOTSTRAP_PASSWORD_LOGIN_ENABLED', true), FILTER_VALIDATE_BOOL),
        'passkey_login' => filter_var(env('BOOTSTRAP_PASSKEY_LOGIN_ENABLED', true), FILTER_VALIDATE_BOOL),
        'managed_android_enrollment' => filter_var(env('BOOTSTRAP_MANAGED_ANDROID_ENROLLMENT_ENABLED', false), FILTER_VALIDATE_BOOL),
        'android_push' => filter_var(env('BOOTSTRAP_ANDROID_PUSH_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],
    'android_push' => [
        'metadata_revision' => env('BOOTSTRAP_ANDROID_PUSH_METADATA_REVISION') !== null ? (int) env('BOOTSTRAP_ANDROID_PUSH_METADATA_REVISION') : null,
        'public_client_metadata' => [
            'api_key' => env('BOOTSTRAP_ANDROID_PUSH_PUBLIC_API_KEY'),
            'project_id' => env('BOOTSTRAP_ANDROID_PUSH_PUBLIC_PROJECT_ID'),
            'application_id' => env('BOOTSTRAP_ANDROID_PUSH_PUBLIC_APPLICATION_ID'),
            'sender_id' => env('BOOTSTRAP_ANDROID_PUSH_PUBLIC_SENDER_ID'),
        ],
    ],
];
