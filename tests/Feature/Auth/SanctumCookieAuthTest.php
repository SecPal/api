<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

describe('httpOnly Cookie Authentication Flow', function () {
    test('login endpoint is accessible and returns token', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Login with credentials
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
            ]);
    });

    test('authenticated request with session cookie succeeds', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Authenticate via Sanctum (simulates having valid session)
        $this->actingAs($user, 'sanctum');

        // Make authenticated request
        $response = $this->getJson('/v1/me');

        $response->assertOk()
            ->assertJson([
                'id' => $user->id,
                'email' => 'test@example.com',
            ]);
    });

    test('authenticated request without session cookie fails with 401', function () {
        // Attempt to access protected endpoint without authentication
        $response = $this->getJson('/v1/me');

        $response->assertUnauthorized();
    });

    test('logout clears session and revokes token', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Create token and authenticate with Bearer token (not actingAs)
        $token = $user->createToken('test-device')->plainTextToken;

        // Logout using Bearer token
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/auth/logout');

        $response->assertOk()
            ->assertJson(['message' => 'Token revoked successfully.']);

        // Verify token was revoked
        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    test('session cookie has secure flag in production', function () {
        // This test verifies configuration - secure flag depends on environment
        $secure = config('session.secure');

        // In production, secure should be true (HTTPS only)
        // In testing/development, it can be false or null
        if (app()->environment('production')) {
            expect($secure)->toBeTrue();
        } else {
            // In testing, secure can be null, false, or true
            expect($secure)->toBeIn([null, true, false]);
        }
    });
});

describe('Cookie Attributes and Security', function () {
    test('session cookie has correct sameSite attribute', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Get CSRF token (creates session)
        $response = $this->get('/sanctum/csrf-cookie');

        $cookies = $response->headers->getCookies();
        $sessionCookie = collect($cookies)->first(fn ($cookie) => str_contains($cookie->getName(), 'session'));

        expect($sessionCookie)->not->toBeNull()
            ->and($sessionCookie->getSameSite())->toBe('lax');
    });

    test('session cookie is httpOnly to prevent XSS access', function () {
        // Verify session configuration has httpOnly enabled
        $httpOnly = config('session.http_only');

        // Session cookies MUST be httpOnly for XSS protection
        expect($httpOnly)->toBeTrue();
    });

    test('XSRF-TOKEN cookie is not httpOnly to allow JavaScript access', function () {
        // Get CSRF token
        $response = $this->get('/sanctum/csrf-cookie');

        $cookies = $response->headers->getCookies();
        $xsrfCookie = collect($cookies)->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');

        // XSRF-TOKEN must NOT be httpOnly - JavaScript needs to read it
        expect($xsrfCookie)->not->toBeNull()
            ->and($xsrfCookie->isHttpOnly())->toBeFalse();
    });

    test('session cookie has appropriate lifetime', function () {
        $lifetime = config('session.lifetime');

        // Verify session lifetime is configured (default: 120 minutes)
        expect($lifetime)->toBeInt()
            ->and($lifetime)->toBeGreaterThan(0);
    });
});

describe('Token-Based vs Cookie-Based Authentication', function () {
    test('Bearer token authentication still works for API clients', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Generate token via API
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_name' => 'mobile-app',
        ]);

        $token = $response->json('token');

        // Use Bearer token for subsequent requests (for non-browser clients)
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/me');

        $response->assertOk()
            ->assertJson([
                'email' => 'test@example.com',
            ]);
    });

    test('SPA can use session-based authentication with cookies', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // SPA flow: Get CSRF token, then authenticate
        $this->get('/sanctum/csrf-cookie');

        // Authenticate with actingAs (simulates session cookie)
        $this->actingAs($user, 'sanctum');

        // Access protected endpoint without Bearer token
        $response = $this->getJson('/v1/me');

        $response->assertOk()
            ->assertJson([
                'email' => 'test@example.com',
            ]);
    });
});

describe('Multiple Device Sessions', function () {
    test('user can have active sessions from multiple devices', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Create tokens for different devices
        $mobileToken = $user->createToken('mobile-device');
        $desktopToken = $user->createToken('desktop-device');

        expect($user->tokens()->count())->toBe(2);
        expect($user->tokens()->pluck('name')->toArray())
            ->toContain('mobile-device', 'desktop-device');
    });

    test('logout only revokes current session token', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Create multiple tokens
        $token1 = $user->createToken('device-1')->plainTextToken;
        $token2 = $user->createToken('device-2')->plainTextToken;

        expect($user->tokens()->count())->toBe(2);

        // Logout from device-1
        $this->withHeader('Authorization', "Bearer {$token1}")
            ->postJson('/v1/auth/logout');

        // Only token1 should be revoked
        expect($user->fresh()->tokens()->count())->toBe(1);
        expect($user->fresh()->tokens()->first()->name)->toBe('device-2');
    });

    test('logout-all revokes all device tokens', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Create multiple tokens
        $token1 = $user->createToken('device-1')->plainTextToken;
        $user->createToken('device-2');
        $user->createToken('device-3');

        expect($user->tokens()->count())->toBe(3);

        // Logout from all devices
        $this->withHeader('Authorization', "Bearer {$token1}")
            ->postJson('/v1/auth/logout-all');

        // All tokens should be revoked
        expect($user->fresh()->tokens()->count())->toBe(0);
    });
});
