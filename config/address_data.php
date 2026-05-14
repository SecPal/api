<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [

    /*
    |--------------------------------------------------------------------------
    | CSV source URL (OpenPLZ API Data — Germany streets)
    |--------------------------------------------------------------------------
    */

    'source_url' => env(
        'ADDRESS_DATA_SOURCE_URL',
        'https://github.com/openpotato/openplzapi.data/raw/refs/heads/main/src/de/osm/streets.updated.csv',
    ),

    'country' => env('ADDRESS_DATA_COUNTRY', 'DE'),

    /*
    |--------------------------------------------------------------------------
    | Schedule hint (documented default; actual schedule is in routes/console.php)
    |--------------------------------------------------------------------------
    */

    'update_schedule' => env('ADDRESS_DATA_UPDATE_FREQUENCY', 'weekly'),

    'download_timeout' => (int) env('ADDRESS_DATA_DOWNLOAD_TIMEOUT', 600),

    'chunk_rows' => (int) env('ADDRESS_DATA_CHUNK_ROWS', 2000),

    'chunk_size' => (int) env('ADDRESS_DATA_CHUNK_SIZE', 8192),

    'default_limit' => (int) env('ADDRESS_DATA_DEFAULT_LIMIT', 20),

    'max_limit' => (int) env('ADDRESS_DATA_MAX_LIMIT', 50),

    /*
    |--------------------------------------------------------------------------
    | First-time import during composer setup (see composer.json)
    | Set ADDRESS_DATA_IMPORT_ON_SETUP=false for offline installs or CI without network.
    |--------------------------------------------------------------------------
    */

    'import_on_setup' => filter_var(env('ADDRESS_DATA_IMPORT_ON_SETUP', 'true'), FILTER_VALIDATE_BOOLEAN),

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
