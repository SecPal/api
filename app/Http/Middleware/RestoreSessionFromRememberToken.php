<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restore session from remember token for SPA requests.
 *
 * Problem: Laravel's remember token functionality only works with the SessionGuard
 * when accessed via web routes. Sanctum SPA authentication doesn't automatically
 * restore sessions from remember tokens when the session expires but the remember
 * cookie is still valid.
 *
 * Solution: This middleware checks if there's no authenticated user but a valid
 * remember cookie exists, and restores the session from the remember token.
 *
 * This enables true "stay logged in" behavior for PWAs where users may be
 * inactive for hours but should remain authenticated.
 */
class RestoreSessionFromRememberToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only process if:
        // 1. No user is currently authenticated
        // 2. Request has a session (stateful SPA request)
        // 3. There's a remember cookie
        if (
            ! Auth::guard('web')->check() &&
            $request->hasSession() &&
            $this->hasRememberCookie($request)
        ) {
            // Let Laravel's SessionGuard attempt to restore from remember token
            // This calls loginByRememberToken() internally
            Auth::guard('web')->user();
        }

        return $next($request);
    }

    /**
     * Check if the request has a remember cookie.
     */
    private function hasRememberCookie(Request $request): bool
    {
        /** @var \Illuminate\Auth\SessionGuard $guard */
        $guard = Auth::guard('web');
        $cookieName = $guard->getRecallerName();

        return $request->cookies->has($cookieName);
    }
}
