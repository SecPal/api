<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    /*
    |--------------------------------------------------------------------------
    | Key Encryption Key (KEK) Path
    |--------------------------------------------------------------------------
    |
    | Path to the file containing the master KEK (32+ bytes, Base64 or raw).
    | MUST be readable only by the application user (chmod 600).
    |
    */
    'kek_path' => env('KEK_PATH', storage_path('app/kek')),

    /*
    |--------------------------------------------------------------------------
    | Key Cache TTL (minutes)
    |--------------------------------------------------------------------------
    |
    | Unwrapped keys are cached in memory for performance.
    | Set to 0 to disable caching (not recommended in production).
    |
    */
    'cache_ttl' => env('KEY_CACHE_TTL', 5),

    /*
    |--------------------------------------------------------------------------
    | Key Rotation Settings
    |--------------------------------------------------------------------------
    */
    'rotation' => [
        'batch_size' => env('KEY_ROTATION_BATCH_SIZE', 100),
        'checkpoint_interval' => env('KEY_ROTATION_CHECKPOINT', 500),
    ],
];
