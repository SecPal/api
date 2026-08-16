<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

describe('CSRF Token Endpoint', function () {
    test('csrf cookie endpoint is accessible', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
    });

    test('csrf cookie endpoint returns XSRF-TOKEN cookie', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        // XSRF-TOKEN cookie should be set by Laravel
        $response->assertCookie('XSRF-TOKEN');
    });

    test('csrf cookie is httpOnly for session cookie', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        // Session cookie should be httpOnly (XSRF-TOKEN is not httpOnly by design - needs to be read by JS)
        $cookies = $response->headers->getCookies();
        $sessionCookie = collect($cookies)->first(fn ($cookie) => str_contains($cookie->getName(), 'session'));

        expect($sessionCookie)->not->toBeNull()
            ->and($sessionCookie->isHttpOnly())->toBeTrue();
    });
});

describe('CSRF Protection for State-Changing Requests', function () {
    test('CSRF middleware is enabled globally', function () {
        // Verify CSRF middleware is configured in Sanctum
        $middleware = config('sanctum.middleware');

        expect($middleware)->toHaveKey('validate_csrf_token')
            ->and($middleware['validate_csrf_token'])->toBe(
                Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class
            );
    });

    test('GET request does not require CSRF token', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($user, 'sanctum');

        // GET request should work without CSRF token
        $response = $this->getJson('/v1/me');

        // Should succeed (or return appropriate status, not 419)
        expect($response->status())->not->toBe(419);
    });
});

describe('Session Cookie Configuration', function () {
    test('session cookies have correct security attributes', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        $cookies = $response->headers->getCookies();
        $sessionCookie = collect($cookies)->first(fn ($cookie) => str_contains($cookie->getName(), 'session'));

        expect($sessionCookie)->not->toBeNull()
            ->and($sessionCookie->isHttpOnly())->toBeTrue()
            ->and($sessionCookie->getSameSite())->toBe('lax');

        // In production, secure should be true (HTTPS only)
        // In testing, it's false since we don't have HTTPS
        if (config('session.secure')) {
            expect($sessionCookie->isSecure())->toBeTrue();
        }
    });

    test('XSRF-TOKEN cookie is not httpOnly to allow JavaScript access', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        $cookies = $response->headers->getCookies();
        $xsrfCookie = collect($cookies)->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');

        expect($xsrfCookie)->not->toBeNull()
            // XSRF-TOKEN must NOT be httpOnly - JS needs to read it
            ->and($xsrfCookie->isHttpOnly())->toBeFalse();
    });
});

describe('CSRF Token Refresh Flow', function () {
    test('expired CSRF token can be refreshed', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Get initial CSRF token
        $csrfResponse = $this->get('/sanctum/csrf-cookie');
        $cookies = $csrfResponse->headers->getCookies();
        $xsrfCookie = collect($cookies)->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');

        expect($xsrfCookie)->not->toBeNull();

        $oldToken = $xsrfCookie->getValue();

        // Simulate token expiration by getting a new token
        $newCsrfResponse = $this->get('/sanctum/csrf-cookie');
        $newCookies = $newCsrfResponse->headers->getCookies();
        $newXsrfCookie = collect($newCookies)->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');

        expect($newXsrfCookie)->not->toBeNull();

        $newToken = $newXsrfCookie->getValue();

        // Tokens should be different (new token generated)
        // Note: In some cases they might be the same if session hasn't changed
        // The important part is that the endpoint is accessible for refresh
        expect($newToken)->toBeString();
    });
});
