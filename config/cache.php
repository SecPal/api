<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: MIT

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Cache Serializable Classes
    |--------------------------------------------------------------------------
    |
    | Laravel 13 hardens cache unserialization by default. Keep arbitrary PHP
    | object deserialization disabled unless specific cached classes need to be
    | allow-listed here in the future.
    |
    */

    'serializable_classes' => false,

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Database is the shared runtime store and lock authority. Array is a test
    | helper; file is an explicit degraded fallback for health throttling only.
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => 'pgsql',
            'table' => 'cache',
            'lock_connection' => 'pgsql',
            'lock_table' => 'cache_locks',
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix database cache keys to avoid collisions with another application
    | that intentionally shares the same PostgreSQL database.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

];
