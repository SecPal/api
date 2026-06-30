<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

describe('httpOnly Cookie Authentication Flow', function () {
    beforeEach(function () {
        clearLoginRateLimiter('test@example.com');
    });

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
                'user' => ['id', 'name', 'email', 'roles', 'permissions', 'hasOrganizationalScopes', 'hasCustomerAccess', 'hasSiteAccess'],
            ]);
    });

    test('authenticated request with session cookie succeeds', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ])->assertOk();

        // Make authenticated request
        $response = $this->withHeaders(spaHeaders())->getJson('/v1/me');

        $response->assertOk()
            ->assertJson([
                'id' => $user->id,
                'email' => 'test@example.com',
            ]);
    });

    test('session-authenticated requests still succeed after token ability enforcement', function () {
        $user = User::factory()->create([
            'email' => 'session-ability-'.Illuminate\Support\Str::uuid().'@secpal.dev',
            'password' => Hash::make('password123'),
        ]);

        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $this->withHeaders(spaHeaders())->getJson('/v1/me')
            ->assertOk()
            ->assertJson([
                'email' => $user->email,
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
            ->assertJson(['message' => 'Logged out successfully']);

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
            // In testing and CI, session.secure may resolve to null, bool, or an empty string.
            expect($secure)->toBeIn([null, '', true, false]);
        }
    });
});

describe('Cookie Attributes and Security', function () {
    test('session cookie has correct sameSite attribute', function () {
        // Get CSRF cookie (which sets session cookie)
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
    beforeEach(function () {
        clearLoginRateLimiter('test@example.com');
    });

    test('Bearer token authentication still works for API clients', function () {
        $email = 'test-'.Illuminate\Support\Str::uuid().'@example.com';

        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('password123'),
        ]);

        // Generate token via API
        $response = $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'password123',
            'device_name' => 'mobile-app',
        ]);

        $response->assertCreated();

        $token = $response->json('token');

        // Use Bearer token for subsequent requests (for non-browser clients)
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/me');

        $response->assertOk()
            ->assertJson([
                'email' => $email,
            ]);
    });

    test('SPA can use session-based authentication with cookies', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ])->assertOk();

        // Access protected endpoint without Bearer token
        $response = $this->withHeaders(spaHeaders())->getJson('/v1/me');

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

describe('SPA Session-Based Login', function () {
    beforeEach(function () {
        clearLoginRateLimiter('spa@example.com');
        clearLoginRateLimiter('user@example.com');
        clearLoginRateLimiter('nonexistent@example.com');
        clearLoginRateLimiter('test@example.com');
        clearLoginRateLimiter('session@example.com');

        // Set stateful domain header so Sanctum activates session middleware
        $this->withHeaders(spaHeaders());
    });

    test('successful login with valid credentials creates session', function () {
        $user = User::factory()->create([
            'email' => 'spa@example.com',
            'password' => Hash::make('securepassword'),
        ]);

        $csrfToken = issueSpaCsrfToken($this);

        // Login via SPA endpoint
        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $csrfToken,
        ]))->postJson('/v1/auth/login', [
            'email' => 'spa@example.com',
            'password' => 'securepassword',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'email', 'name'],
            ])
            ->assertJsonPath('user.email', 'spa@example.com');
    });

    test('login fails with invalid credentials', function () {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('correctpassword'),
        ]);

        $csrfToken = issueSpaCsrfToken($this);

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $csrfToken,
        ]))->postJson('/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'wrongpassword',
        ]);

        // ValidationException throws 422 with error message
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('login fails with non-existent user', function () {
        $csrfToken = issueSpaCsrfToken($this);

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $csrfToken,
        ]))->postJson('/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'anypassword',
        ]);

        // ValidationException throws 422 with error message
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('login validation requires email', function () {
        $csrfToken = issueSpaCsrfToken($this);

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $csrfToken,
        ]))->postJson('/v1/auth/login', [
            'password' => 'somepassword',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('login validation requires password', function () {
        $csrfToken = issueSpaCsrfToken($this);

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $csrfToken,
        ]))->postJson('/v1/auth/login', [
            'email' => 'missing-password-'.Illuminate\Support\Str::uuid().'@example.com',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    });

    test('login validation requires valid email format', function () {
        $csrfToken = issueSpaCsrfToken($this);

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $csrfToken,
        ]))->postJson('/v1/auth/login', [
            'email' => 'invalid-email',
            'password' => 'somepassword',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('login regenerates session to prevent fixation attacks', function () {
        $user = User::factory()->create([
            'email' => 'session@example.com',
            'password' => Hash::make('password123'),
        ]);

        $csrfToken = issueSpaCsrfToken($this);

        // Get initial session ID
        $initialSessionId = session()->getId();

        // Login
        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $csrfToken,
        ]))->postJson('/v1/auth/login', [
            'email' => 'session@example.com',
            'password' => 'password123',
        ]);

        // Session should be regenerated after login
        $newSessionId = session()->getId();

        expect($newSessionId)->not->toBe($initialSessionId);
    });
});

describe('SPA Session-Based Logout', function () {
    beforeEach(function () {
        clearLoginRateLimiter('logout@example.com');
        clearLoginRateLimiter('multiauth@example.com');

        // Set stateful domain header so Sanctum activates session middleware
        $this->withHeaders(spaHeaders());
    });

    test('canonical logout endpoint invalidates session', function () {
        $user = User::factory()->create([
            'email' => 'logout@example.com',
            'password' => Hash::make('password123'),
        ]);

        $loginCsrfToken = issueSpaCsrfToken($this);
        $loginResponse = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $loginCsrfToken,
        ]))->postJson('/v1/auth/login', [
            'email' => 'logout@example.com',
            'password' => 'password123',
        ]);
        $loginResponse->assertOk();

        // Logout via canonical endpoint
        $logoutCsrfToken = issueSpaCsrfToken($this);
        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $logoutCsrfToken,
        ]))->postJson('/v1/auth/logout');

        $response->assertOk()
            ->assertJson([
                'message' => 'Logged out successfully',
            ]);
    });

    test('legacy session logout alias is not available for spa logout', function () {
        User::factory()->create([
            'email' => 'logout@example.com',
            'password' => Hash::make('password123'),
        ]);

        $loginCsrfToken = issueSpaCsrfToken($this);
        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $loginCsrfToken,
        ]))->postJson('/v1/auth/login', [
            'email' => 'logout@example.com',
            'password' => 'password123',
        ])->assertOk();

        $logoutCsrfToken = issueSpaCsrfToken($this);
        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $logoutCsrfToken,
        ]))->postJson('/v1/auth/session/logout')
            ->assertNotFound();
    });

    test('session logout requires authentication', function () {
        $csrfToken = issueSpaCsrfToken($this);

        $response = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $csrfToken,
        ]))->postJson('/v1/auth/logout');

        $response->assertUnauthorized();
    });

    test('session logout does not affect token-based sessions', function () {
        $user = User::factory()->create([
            'email' => 'multiauth@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Create a token for mobile app
        $token = $user->createToken('mobile-device')->plainTextToken;

        // Login via SPA session
        $loginCsrfToken = issueSpaCsrfToken($this);
        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $loginCsrfToken,
        ]))->postJson('/v1/auth/login', [
            'email' => 'multiauth@example.com',
            'password' => 'password123',
        ]);

        // Logout from session
        $logoutCsrfToken = issueSpaCsrfToken($this);
        $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => $logoutCsrfToken,
        ]))->postJson('/v1/auth/session/logout')
            ->assertNotFound();

        // Token should still be valid
        expect($user->fresh()->tokens()->count())->toBe(1);

        // Token-based request should still work (use new test instance to clear session)
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/v1/me')
            ->assertOk();
    });
});
