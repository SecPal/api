<?php

// SPDX-FileCopyrightText: 2025 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    /*
    |--------------------------------------------------------------------------
    | Translation.io API Key
    |--------------------------------------------------------------------------
    |
    | Your Translation.io API key. This should be stored in your .env file
    | as TRANSLATIONIO_KEY for security. Never commit the actual key to
    | version control.
    |
    */
    'key' => env('TRANSLATIONIO_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Source Locale
    |--------------------------------------------------------------------------
    |
    | The source locale is the language in which you write your application.
    | All translations will be created from this base language.
    |
    */
    'source_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Target Locales
    |--------------------------------------------------------------------------
    |
    | The target locales are the languages you want your application to be
    | translated into. Add or remove locales as needed.
    |
    */
    'target_locales' => ['de'],

    /*
    |--------------------------------------------------------------------------
    | Gettext Parse Paths
    |--------------------------------------------------------------------------
    |
    | Directories to scan for Gettext strings. Translation.io will scan these
    | paths to extract translatable strings from your application code.
    |
    */
    'gettext_parse_paths' => ['app', 'resources'],
];
