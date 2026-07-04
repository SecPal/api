<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security Headers Middleware
 *
 * Adds security-related HTTP headers to all responses to protect against
 * common web vulnerabilities.
 */
class SecurityHeaders
{
    private const CONTENT_SECURITY_POLICY = "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'; img-src 'self'; style-src 'unsafe-inline'; script-src 'none'; object-src 'none'";

    private const PERMISSIONS_POLICY = 'accelerometer=(), autoplay=(), camera=(), clipboard-read=(), clipboard-write=(), display-capture=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';

    private const STRICT_TRANSPORT_SECURITY = 'max-age=63072000; includeSubDomains; preload';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent clickjacking attacks by disallowing the page to be framed
        $response->headers->set('X-Frame-Options', 'DENY');

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Disable the legacy XSS auditor in favor of modern browser behavior.
        $response->headers->set('X-XSS-Protection', '0');

        // Control referrer information sent with requests
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Prevent caching of responses handled by this middleware in browsers
        // and intermediaries.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        // API responses should deny browser capabilities and cross-origin window
        // relationships consistently with the PWA, while keeping API subresources
        // readable to the first-party app host on the same site.
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        $response->headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
        $response->headers->set('Cross-Origin-Embedder-Policy', 'require-corp');
        $response->headers->set('Origin-Agent-Cluster', '?1');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // HSTS is only meaningful on HTTPS responses. Applying it whenever the
        // request is secure keeps the live API host hardened even if APP_ENV is
        // not exactly "production" on the server.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', self::STRICT_TRANSPORT_SECURITY);
        }

        return $response;
    }
}
