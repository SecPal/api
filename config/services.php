<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: MIT

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

    'web_push' => [
        'public_key' => env('BOOTSTRAP_WEB_PUSH_PUBLIC_VAPID_KEY'),
        'subject' => env('WEB_PUSH_VAPID_SUBJECT'),
        'private_key' => env('WEB_PUSH_VAPID_PRIVATE_KEY'),
        'ttl' => env('WEB_PUSH_DELIVERY_TTL', 300),
        'urgency' => env('WEB_PUSH_DELIVERY_URGENCY', 'normal'),
        'connect_timeout' => env('WEB_PUSH_DELIVERY_CONNECT_TIMEOUT', 5),
        'timeout' => env('WEB_PUSH_DELIVERY_TIMEOUT', 20),
    ],

    'opentimestamps' => [
        'bitcoin_header_api_bases' => env(
            'OTS_BITCOIN_HEADER_API_BASES',
            'https://blockstream.info/api,https://mempool.space/api',
        ),
        'verification_cache_ttl_seconds' => env('OTS_VERIFICATION_CACHE_TTL_SECONDS', 3600),
    ],

];
