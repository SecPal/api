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
    ],
];
