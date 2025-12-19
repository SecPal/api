<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ForceJsonResponse Middleware
 *
 * Forces all API requests to expect and receive JSON responses.
 * This prevents Laravel from returning HTML error pages for validation errors.
 *
 * Without this middleware, when a validation error occurs and the request
 * doesn't have 'Accept: application/json', Laravel returns an HTML error page
 * (status 422 with HTML content), which causes frontend parsing errors.
 *
 * This middleware ensures consistent JSON responses for all API endpoints.
 */
class ForceJsonResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Force Accept header to application/json for all API requests
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
