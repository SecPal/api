<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromHeader
{
    private const ALLOW_USER_LOOKUP_ATTRIBUTE = 'locale.allow_user_lookup';

    private const USER_LOOKUP_COMPLETED_ATTRIBUTE = 'locale.user_lookup_completed';

    private const USER_LOCALE_APPLIED_ATTRIBUTE = 'locale.user_locale_applied';

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

        if ($request->attributes->getBoolean(self::USER_LOCALE_APPLIED_ATTRIBUTE)) {
            return $next($request);
        }

        $canResolveUserLocale = ! $request->attributes->getBoolean(self::USER_LOOKUP_COMPLETED_ATTRIBUTE)
            && $request->attributes->getBoolean(self::ALLOW_USER_LOOKUP_ATTRIBUTE);

        $request->attributes->set(self::ALLOW_USER_LOOKUP_ATTRIBUTE, true);

        if ($canResolveUserLocale) {
            $request->attributes->set(self::USER_LOOKUP_COMPLETED_ATTRIBUTE, true);

            $preferredLocale = $request->user()?->preferred_locale;
            if (is_string($preferredLocale) && in_array($preferredLocale, $supportedLocales, true)) {
                App::setLocale($preferredLocale);
                $request->attributes->set(self::USER_LOCALE_APPLIED_ATTRIBUTE, true);

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
