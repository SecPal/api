<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

$parseCorsAllowedOrigins = static function (string $configuredOrigins): array {
    $allowedOrigins = array_values(array_unique(array_filter(
        array_map(static fn (string $origin): string => trim($origin), explode(',', $configuredOrigins)),
        static fn (string $origin): bool => $origin !== ''
    )));

    foreach ($allowedOrigins as $origin) {
        if ($origin === 'null') {
            throw new InvalidArgumentException('CORS_ALLOWED_ORIGINS must not include the null origin.');
        }

        if (str_contains($origin, '*')) {
            throw new InvalidArgumentException('CORS_ALLOWED_ORIGINS must contain exact origins only; wildcards are not allowed.');
        }

        $parsedOrigin = parse_url($origin);

        $isValidOrigin = filter_var($origin, FILTER_VALIDATE_URL) !== false
            && is_array($parsedOrigin)
            && in_array($parsedOrigin['scheme'] ?? null, ['http', 'https'], true)
            && isset($parsedOrigin['host'])
            && ! array_key_exists('path', $parsedOrigin)
            && ! array_key_exists('query', $parsedOrigin)
            && ! array_key_exists('fragment', $parsedOrigin)
            && ! array_key_exists('user', $parsedOrigin)
            && ! array_key_exists('pass', $parsedOrigin);

        if (! $isValidOrigin) {
            throw new InvalidArgumentException('CORS_ALLOWED_ORIGINS must contain exact scheme://host[:port] origins without paths, credentials, queries, or fragments.');
        }
    }

    return $allowedOrigins;
};

$configuredAllowedOrigins = $parseCorsAllowedOrigins((string) env('CORS_ALLOWED_ORIGINS', 'https://app.secpal.dev'));

$exactAllowedOriginPatterns = array_map(
    static fn (string $origin): string => '#^'.preg_quote($origin, '#').'$#',
    $configuredAllowedOrigins,
);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | For SPA authentication with httpOnly cookies, CORS must:
    | - Allow credentials (supports_credentials = true)
    | - Specify allowed origins explicitly (cannot use '*')
    | - Include CSRF token header (X-XSRF-TOKEN)
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'v1/*', 'health', 'health/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => explode(',', (string) env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS')),

    // Use exact-match patterns to avoid the CORS library's single-origin static fallback.
    'allowed_origins' => [],

    'allowed_origins_patterns' => $exactAllowedOriginPatterns,

    'allowed_headers' => explode(',', (string) env('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization,X-Requested-With,X-XSRF-TOKEN')),

    'exposed_headers' => [
        'Retry-After',
        'X-RateLimit-Remaining',
        'X-RateLimit-Limit',
        'X-RateLimit-Reset',
    ],

    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', true),

];
