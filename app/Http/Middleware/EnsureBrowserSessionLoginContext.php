<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject stateless clients on the browser-only session login endpoint.
 *
 * The /v1/auth/login route is reserved for first-party browser/SPA requests
 * that have already passed through Sanctum's stateful frontend pipeline.
 * Direct API clients must use /v1/auth/token instead.
 */
class EnsureBrowserSessionLoginContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('sanctum') === true && $request->hasSession()) {
            return $next($request);
        }

        return response()->json([
            'message' => __('This endpoint requires a browser session context. Use /v1/auth/token for API clients.'),
        ], Response::HTTP_BAD_REQUEST);
    }
}
