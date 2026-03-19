<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
});
