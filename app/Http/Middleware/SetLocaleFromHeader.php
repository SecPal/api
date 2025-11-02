<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromHeader
{
    /**
     * Handle an incoming request and set the application locale based on Accept-Language header.
     *
     * The middleware reads the Accept-Language header from the request and sets the application
     * locale accordingly. If no header is present or the language is not supported, it defaults
     * to the application's configured locale.
     *
     * Supported locales: en (English), de (German)
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = ['en', 'de'];

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
