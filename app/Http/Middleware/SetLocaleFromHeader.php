<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromHeader
{
    private const ALLOW_USER_LOOKUP_ATTRIBUTE = 'locale.allow_user_lookup';

    /**
     * Handle an incoming request and set the application locale.
     *
     * Resolution order:
     * 1. Authenticated user's `preferred_locale` (available in the api middleware group
     *    after Sanctum runs; returns null in the global pass where auth is not yet resolved).
     * 2. The best-matching language from the Accept-Language request header.
     * 3. The application's configured default locale.
     *
     * Supported locales: en (English), de (German)
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = ['en', 'de'];

        $canResolveUserLocale = $request->attributes->getBoolean(self::ALLOW_USER_LOOKUP_ATTRIBUTE)
            || $request->bearerToken() !== null;

        // The global pass sets the marker so the later API-group pass can resolve
        // session-authenticated users without forcing auth resolution twice.
        $request->attributes->set(self::ALLOW_USER_LOOKUP_ATTRIBUTE, true);

        if ($canResolveUserLocale) {
            $preferredLocale = $request->user()?->preferred_locale;
            if (is_string($preferredLocale) && in_array($preferredLocale, $supportedLocales, true)) {
                App::setLocale($preferredLocale);

                return $next($request);
            }
        }

        // Get preferred language from Accept-Language header
        $locale = $request->getPreferredLanguage($supportedLocales);

        // Fall back to default if no match
        if ($locale === null) {
            $defaultLocale = config('app.locale');
            $locale = is_string($defaultLocale) ? $defaultLocale : 'en';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
