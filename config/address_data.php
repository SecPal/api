<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [

    /*
    |--------------------------------------------------------------------------
    | CSV source URL (OpenPLZ API Data — Germany streets)
    |--------------------------------------------------------------------------
    */

    'source_url' => 'https://raw.githubusercontent.com/openpotato/openplzapi.data/c26389b30573ab2738d0b14fd350f143aa0a1251/src/de/osm/streets.updated.csv',

    'expected_sha256' => '1066a96a6b3a16629a94c834fca49fe58a2bb01558e17242c53a12d68b290088',

    'country' => 'DE',

    'download_timeout' => (int) env('ADDRESS_DATA_DOWNLOAD_TIMEOUT', 600),

    'chunk_rows' => (int) env('ADDRESS_DATA_CHUNK_ROWS', 2000),

    'default_limit' => (int) env('ADDRESS_DATA_DEFAULT_LIMIT', 20),

    'max_limit' => (int) env('ADDRESS_DATA_MAX_LIMIT', 50),

    /*
    |--------------------------------------------------------------------------
    | Optional database seeding / --setup-only imports
    | Enabled by default. Composer setup and explicit deployment imports always
    | run independently of this optional seeding switch.
    |--------------------------------------------------------------------------
    */

    'import_on_setup' => filter_var(env('ADDRESS_DATA_IMPORT_ON_SETUP', 'true'), FILTER_VALIDATE_BOOLEAN),

    'setup_source_path' => env('ADDRESS_DATA_SETUP_SOURCE_PATH'),

    /*
    |--------------------------------------------------------------------------
    | License & attribution (ODbL — verify suitability before production use)
    |--------------------------------------------------------------------------
    */

    'source_name' => 'OpenPLZ API Data',

    'license_name' => 'Open Database License v1.0',

    'license_spdx' => 'ODbL-1.0',

    'attribution' => 'OpenPLZ API Data / OpenPotato; © OpenStreetMap contributors (https://www.openstreetmap.org/copyright). Data licensed under ODbL-1.0 (https://opendatacommons.org/licenses/odbl/1-0/).',

];
