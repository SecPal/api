<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: MIT

$forcedTestDatabase = getenv('SECPAL_TEST_DATABASE');
$forcedTestSchema = getenv('SECPAL_TEST_SCHEMA');

if (! is_string($forcedTestDatabase) || $forcedTestDatabase === '') {
    $forcedTestDatabase = $_ENV['SECPAL_TEST_DATABASE'] ?? $_SERVER['SECPAL_TEST_DATABASE'] ?? null;
}

if (! is_string($forcedTestSchema) || $forcedTestSchema === '') {
    $forcedTestSchema = $_ENV['SECPAL_TEST_SCHEMA'] ?? $_SERVER['SECPAL_TEST_SCHEMA'] ?? null;
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | PostgreSQL is the supported SecPal runtime connection. SQLite remains a
    | bounded helper for migration characterization tests and is rejected in
    | production by the runtime infrastructure contract.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => null,
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => is_string($forcedTestDatabase) && $forcedTestDatabase !== ''
                ? $forcedTestDatabase
                : env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => is_string($forcedTestSchema) && $forcedTestSchema !== ''
                ? $forcedTestSchema.',public'
                : env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'sslrootcert' => env('DB_SSLROOTCERT'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];
