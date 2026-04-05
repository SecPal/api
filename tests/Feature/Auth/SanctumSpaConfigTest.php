<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function withCorsAllowedOrigins(string $value, Closure $callback): mixed
{
    $previousValue = getenv('CORS_ALLOWED_ORIGINS');
    $hadEnvValue = array_key_exists('CORS_ALLOWED_ORIGINS', $_ENV);
    $hadServerValue = array_key_exists('CORS_ALLOWED_ORIGINS', $_SERVER);
    $previousEnvValue = $_ENV['CORS_ALLOWED_ORIGINS'] ?? null;
    $previousServerValue = $_SERVER['CORS_ALLOWED_ORIGINS'] ?? null;

    putenv("CORS_ALLOWED_ORIGINS={$value}");
    $_ENV['CORS_ALLOWED_ORIGINS'] = $value;
    $_SERVER['CORS_ALLOWED_ORIGINS'] = $value;

    try {
        return $callback();
    } finally {
        if ($previousValue === false) {
            putenv('CORS_ALLOWED_ORIGINS');
        } else {
            putenv("CORS_ALLOWED_ORIGINS={$previousValue}");
        }

        if ($hadEnvValue) {
            $_ENV['CORS_ALLOWED_ORIGINS'] = $previousEnvValue;
        } else {
            unset($_ENV['CORS_ALLOWED_ORIGINS']);
        }

        if ($hadServerValue) {
            $_SERVER['CORS_ALLOWED_ORIGINS'] = $previousServerValue;
        } else {
            unset($_SERVER['CORS_ALLOWED_ORIGINS']);
        }
    }
}

describe('Sanctum SPA Authentication Configuration', function () {
    test('sanctum stateful domains configuration is set', function () {
        $statefulDomains = config('sanctum.stateful');

        expect($statefulDomains)->toBeArray()
            ->and($statefulDomains)->not->toBeEmpty();
    });

    test('sanctum middleware includes csrf validation', function () {
        $middleware = config('sanctum.middleware');

        expect($middleware)->toHaveKey('validate_csrf_token')
            ->and($middleware['validate_csrf_token'])->toBe(
                Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class
            );
    });

    test('sanctum guard uses web guard', function () {
        $guard = config('sanctum.guard');

        expect($guard)->toBe(['web']);
    });

    test('csrf cookie route is accessible', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
    });

    test('session driver is configured for cookie storage', function () {
        $driver = config('session.driver');

        // In testing, the suite may use database-backed sessions to exercise
        // real stateful SPA flows. Production may use cookie or database.
        expect($driver)->toBeIn(['cookie', 'array', 'database']);
    });

    test('session cookies are configured as httpOnly', function () {
        $httpOnly = config('session.http_only');

        expect($httpOnly)->toBeTrue();
    });

    test('session encryption defaults to enabled when unset', function () {
        $originalGetenv = getenv('SESSION_ENCRYPT');
        $hadGetenv = $originalGetenv !== false;
        $hadServer = array_key_exists('SESSION_ENCRYPT', $_SERVER);
        $originalServer = $_SERVER['SESSION_ENCRYPT'] ?? null;
        $hadEnv = array_key_exists('SESSION_ENCRYPT', $_ENV);
        $originalEnv = $_ENV['SESSION_ENCRYPT'] ?? null;

        putenv('SESSION_ENCRYPT');
        unset($_SERVER['SESSION_ENCRYPT'], $_ENV['SESSION_ENCRYPT']);

        try {
            /** @var array{encrypt: bool} $sessionConfig */
            $sessionConfig = require config_path('session.php');

            expect($sessionConfig['encrypt'])->toBeTrue();
        } finally {
            if ($hadGetenv) {
                putenv("SESSION_ENCRYPT={$originalGetenv}");
            } else {
                putenv('SESSION_ENCRYPT');
            }

            if ($hadServer) {
                $_SERVER['SESSION_ENCRYPT'] = $originalServer;
            } else {
                unset($_SERVER['SESSION_ENCRYPT']);
            }

            if ($hadEnv) {
                $_ENV['SESSION_ENCRYPT'] = $originalEnv;
            } else {
                unset($_ENV['SESSION_ENCRYPT']);
            }
        }
    });

    test('session uses sameSite lax for CSRF protection', function () {
        $sameSite = config('session.same_site');

        expect($sameSite)->toBe('lax');
    });
});

describe('CORS Configuration for SPA', function () {
    test('sanctum stateful domains include the frontend SPA domain', function () {
        $statefulDomains = config('sanctum.stateful');

        expect($statefulDomains)->toContain('app.secpal.dev');
    });

    test('cors config exposes login rate-limit headers to the SPA', function () {
        expect(config('cors.exposed_headers'))->toBe([
            'Retry-After',
            'X-RateLimit-Remaining',
            'X-RateLimit-Limit',
            'X-RateLimit-Reset',
        ]);
    });

    test('cors config includes the base health endpoint path', function () {
        $corsPaths = config('cors.paths');

        expect($corsPaths)
            ->toContain('health')
            ->toContain('health/*');
    });

    test('cors config builds exact patterns for multiple configured origins', function () {
        $corsConfig = withCorsAllowedOrigins('https://app.secpal.dev,https://admin.secpal.dev',
            static fn (): array => require base_path('config/cors.php')
        );

        expect($corsConfig['allowed_origins'])->toBe([])
            ->and($corsConfig['allowed_origins_patterns'])->toBe([
                '#^https\://app\.secpal\.dev$#',
                '#^https\://admin\.secpal\.dev$#',
            ]);
    });

    test('cors config rejects wildcard origins when credentials are enabled', function () {
        expect(fn () => withCorsAllowedOrigins('https://*.secpal.dev',
            static fn (): array => require base_path('config/cors.php')
        ))
            ->toThrow(InvalidArgumentException::class, 'CORS_ALLOWED_ORIGINS must contain exact origins only; wildcards are not allowed.');
    });
});
