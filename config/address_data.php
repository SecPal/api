<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [

    /*
    |--------------------------------------------------------------------------
    | CSV source URL (OpenPLZ API Data — Germany streets)
    |--------------------------------------------------------------------------
    */

    'source_url' => env('ADDRESS_DATA_SOURCE_URL'),

    'expected_sha256' => env('ADDRESS_DATA_EXPECTED_SHA256'),

    'country' => env('ADDRESS_DATA_COUNTRY', 'DE'),

    /*
    |--------------------------------------------------------------------------
    | Production schedule (actual cadence is in routes/console.php)
    |--------------------------------------------------------------------------
    */

    'schedule_enabled' => filter_var(env('ADDRESS_DATA_SCHEDULE_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),

    'download_timeout' => (int) env('ADDRESS_DATA_DOWNLOAD_TIMEOUT', 600),

    'chunk_rows' => (int) env('ADDRESS_DATA_CHUNK_ROWS', 2000),

    'default_limit' => (int) env('ADDRESS_DATA_DEFAULT_LIMIT', 20),

    'max_limit' => (int) env('ADDRESS_DATA_MAX_LIMIT', 50),

    /*
    |--------------------------------------------------------------------------
    | First-time import during composer setup (see composer.json)
    | Disabled by default. A local or immutable remote source still requires the
    | exact expected SHA-256 when explicitly enabled.
    |--------------------------------------------------------------------------
    */

    'import_on_setup' => filter_var(env('ADDRESS_DATA_IMPORT_ON_SETUP', 'false'), FILTER_VALIDATE_BOOLEAN),

    'setup_source_path' => env('ADDRESS_DATA_SETUP_SOURCE_PATH'),

    /*
    |--------------------------------------------------------------------------
    | License & attribution (ODbL — verify suitability before production use)
    |--------------------------------------------------------------------------
    */

    'source_name' => 'OpenPLZ API Data',

    'license_name' => 'Open Database License v1.0',

    'license_spdx' => 'ODbL-1.0',

    'attribution' => 'OpenPLZ API Data / OpenStreetMap contributors / OpenPotato',

];
