<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'fcm' => [
        'project_id' => env('ANDROID_PUSH_FCM_PROJECT_ID'),
        'client_email' => env('ANDROID_PUSH_FCM_CLIENT_EMAIL'),
        'private_key' => env('ANDROID_PUSH_FCM_PRIVATE_KEY'),
        'token_uri' => env('ANDROID_PUSH_FCM_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
        'api_base_url' => env('ANDROID_PUSH_FCM_API_BASE_URL', 'https://fcm.googleapis.com'),
        'connect_timeout' => env('ANDROID_PUSH_FCM_CONNECT_TIMEOUT', 5),
        'timeout' => env('ANDROID_PUSH_FCM_TIMEOUT', 10),
    ],

];
