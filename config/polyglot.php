<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [

    /*
    |--------------------------------------------------------------------------
    | Polyglot Mode Switch
    |--------------------------------------------------------------------------
    |
    | Disabled Polyglot provides Artisan console command to extract translation
    | strings and web panel to manage translations.
    |
    | Enabled Polyglot replaces Laravel Translator service, bringing Gettext
    | support to the Application. With full backward compatibility.
    |
    */

    'enabled' => env('POLYGLOT_GETTEXT', true),

    /*
    |--------------------------------------------------------------------------
    | Application Locales Configuration
    |--------------------------------------------------------------------------
    |
    | The application locales determines the listing of locales that will be used
    | by Polyglot to populate collected translation strings across locales.
    |
    | Every locale should be installed to the system. Inspect installed locales
    | with `locale -a` command. Make sure that system supports application
    | locales with proper codeset.
    |
    | Codeset also matters for xgettext utility.
    |
    | See also
    | https://www.php.net/manual/en/function.setlocale.php
    | https://www.php.net/manual/en/function.bind-textdomain-codeset.php
    */

    'locales' => [
        'en' => ['en_US', 'en_US.utf8', 'en_US.UTF-8'],
        'de' => ['de_DE', 'de_DE.utf8', 'de_DE.UTF-8'],
    ],

    'codeset' => env('GETTEXT_CODESET', 'UTF-8'),

    /*
    |--------------------------------------------------------------------------
    | Sources Configuration
    |--------------------------------------------------------------------------
    |
    | Define one or many folders to collect translation strings.
    |
    */

    'sources' => [
        [
            'include' => [
                app_path(),
                resource_path('views'),
            ],
            'exclude' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Polyglot Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Polyglot will be accessible from. If this
    | setting is null, Polyglot will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('POLYGLOT_DOMAIN', null),

    /*
    |--------------------------------------------------------------------------
    | Polyglot Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Polyglot will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('POLYGLOT_PATH', 'polyglot'),

    /*
    |--------------------------------------------------------------------------
    | Polyglot Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will be assigned to every Polyglot route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => [
        'web',
        App\Http\Middleware\DisablePolyglotUi::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gettext Executables Configuration
    |--------------------------------------------------------------------------
    |
    | Paths to gettext binaries.
    |
    */

    'executables' => [
        'xgettext' => env('XGETTEXT_EXECUTABLE', 'xgettext'),
        'msginit' => env('MSGINIT_EXECUTABLE', 'msginit'),
        'msgmerge' => env('MSGMERGE_EXECUTABLE', 'msgmerge'),
        'msgfmt' => env('MSGFMT_EXECUTABLE', 'msgfmt'),
        'msgcat' => env('MSGCAT_EXECUTABLE', 'msgcat'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gettext Keywords
    |--------------------------------------------------------------------------
    |
    | See https://www.gnu.org/software/gettext/manual/html_node/xgettext-Invocation.html
    |
    | Here you may define additional keywords used in your application.
    |
    */
    'keywords' => [
        '__',
        'trans',
        'trans_choice:1,2',
    ],

    /*
    |--------------------------------------------------------------------------
    | Polyglot Logger Channel
    |--------------------------------------------------------------------------
    |
    | Just for debug.
    |
    */

    'log' => env('POLYGLOT_LOG'),
];
