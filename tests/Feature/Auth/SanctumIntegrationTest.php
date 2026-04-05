<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

dataset('disallowed CORS origins', [
    'evil domain' => 'https://evil.example',
    'null origin' => 'null',
    'subdomain spoof' => 'https://app.secpal.dev.evil.example',
]);

describe('Integration: CORS and Security', function () {
    test('whitelisted origin receives CORS credentials header', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Request from whitelisted origin
        $response = $this->withHeaders([
            'Origin' => 'https://app.secpal.dev',
            'Referer' => 'https://app.secpal.dev/',
        ])->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.secpal.dev');
    });

    test('spa login failures expose rate-limit headers to browser clients', function () {
        clearLoginRateLimiter('test@example.com');

        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->withHeaders(spaCsrfHeaders($this))->postJson('/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.secpal.dev')
            ->assertHeader('X-RateLimit-Remaining', '4');

        $exposedHeaders = $response->headers->get('Access-Control-Expose-Headers');

        expect($exposedHeaders)->not->toBeNull()
            ->and($exposedHeaders)->toContain('Retry-After')
            ->and($exposedHeaders)->toContain('X-RateLimit-Remaining')
            ->and($exposedHeaders)->toContain('X-RateLimit-Limit')
            ->and($exposedHeaders)->toContain('X-RateLimit-Reset');
    });

    test('OPTIONS preflight request succeeds for whitelisted origin', function () {
        $response = $this->call('OPTIONS', '/v1/auth/token', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.secpal.dev',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type,X-XSRF-TOKEN',
        ]);

        $response->assertNoContent();
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.secpal.dev');
        expect($response->headers->get('Access-Control-Allow-Methods'))->toContain('POST');
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
        expect($response->headers->get('Vary'))->toContain('Origin');
        expect($response->headers->get('Vary'))->toContain('Access-Control-Request-Method');
    });

    test('OPTIONS preflight request omits CORS headers for disallowed origins', function (string $origin) {
        $response = $this->call('OPTIONS', '/v1/auth/token', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type,X-XSRF-TOKEN',
        ]);

        $response->assertNoContent();
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBeNull();
        expect($response->headers->get('Vary'))->toContain('Origin');
        expect($response->headers->get('Vary'))->toContain('Access-Control-Request-Method');
    })->with('disallowed CORS origins');

    test('multiple configured origins are allowed exactly without widening matching', function () {
        $originalAllowedOrigins = Config::get('cors.allowed_origins');
        $originalAllowedOriginsPatterns = Config::get('cors.allowed_origins_patterns');

        Config::set('cors.allowed_origins', []);
        Config::set('cors.allowed_origins_patterns', [
            '#^https://app\.secpal\.dev$#',
            '#^https://admin\.secpal\.dev$#',
        ]);

        try {
            $allowedResponse = $this->call('OPTIONS', '/v1/auth/token', [], [], [], [
                'HTTP_ORIGIN' => 'https://admin.secpal.dev',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type,X-XSRF-TOKEN',
            ]);

            $allowedResponse->assertNoContent();
            expect($allowedResponse->headers->get('Access-Control-Allow-Origin'))->toBe('https://admin.secpal.dev');
            expect($allowedResponse->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
            expect($allowedResponse->headers->get('Vary'))->toContain('Origin');

            $disallowedResponse = $this->call('OPTIONS', '/v1/auth/token', [], [], [], [
                'HTTP_ORIGIN' => 'https://admin.secpal.dev.evil.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type,X-XSRF-TOKEN',
            ]);

            $disallowedResponse->assertNoContent();
            expect($disallowedResponse->headers->get('Access-Control-Allow-Origin'))->toBeNull();
            expect($disallowedResponse->headers->get('Access-Control-Allow-Credentials'))->toBeNull();
            expect($disallowedResponse->headers->get('Vary'))->toContain('Origin');
        } finally {
            Config::set('cors.allowed_origins', $originalAllowedOrigins);
            Config::set('cors.allowed_origins_patterns', $originalAllowedOriginsPatterns);
        }
    });

    test('disallowed origins do not receive CORS headers on actual requests', function (string $origin) {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->withHeaders([
            'Origin' => $origin,
        ])->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBeNull();
        expect($response->headers->get('Vary'))->toContain('Origin');
    })->with('disallowed CORS origins');

    test('CORS headers are present on authenticated requests', function () {
        $user = User::factory()->create();

        // Get CSRF token
        $this->get('/sanctum/csrf-cookie');

        // Login
        $response = $this->withHeaders([
            'Origin' => 'https://app.secpal.dev',
        ])->postJson('/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertCreated();
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.secpal.dev');
        expect($response->headers->get('Vary'))->toContain('Origin');
    });
});

describe('Integration: Session Performance', function () {
    test('bearer tokens have reasonable size', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        $token = $response->json('token');

        // Bearer tokens should be < 1KB
        expect(strlen($token))->toBeLessThan(1024);
        expect(strlen($token))->toBeGreaterThan(10); // Minimum valid token length
    });

    test('concurrent sessions from multiple devices work independently', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Device 1 login
        $device1Response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $device1Response->assertCreated();
        $device1Token = $device1Response->json('token');

        // Device 2 login
        $device2Response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $device2Response->assertCreated();
        $device2Token = $device2Response->json('token');

        // Verify different tokens
        expect($device1Token)->not->toBe($device2Token);

        // Both devices can access protected resources
        $response1 = $this->withToken($device1Token)->getJson('/v1/me');
        $response1->assertOk();

        $response2 = $this->withToken($device2Token)->getJson('/v1/me');
        $response2->assertOk();
    });

    test('session configuration is optimized', function () {
        $lifetime = config('session.lifetime');
        $driver = config('session.driver');
        $httpOnly = config('session.http_only');

        expect($lifetime)->toBeInt()->toBeGreaterThan(0);
        // In tests, driver is 'array'; in production: 'database', 'redis', 'cookie'
        expect($driver)->toBeString()->toBeIn(['array', 'database', 'redis', 'cookie']);
        expect($httpOnly)->toBeTrue(); // Critical security setting
    });
});

describe('Integration: Session Expiration', function () {
    test('token expiration defaults to 1440 minutes', function () {
        $expiration = config('sanctum.expiration');
        $lifetime = config('session.lifetime');

        // Personal access tokens default to a 24-hour expiration window.
        expect($expiration)->toBeInt()->toBe(1440);

        // Session lifetime for web guard
        expect($lifetime)->toBeInt()->toBe(120); // 2 hours
    });

    test('expired bearer tokens are rejected', function () {
        $original = config('sanctum.expiration');
        Config::set('sanctum.expiration', 60);

        $user = User::factory()->create([
            'email' => 'expired-token@example.com',
            'password' => Hash::make('password123'),
        ]);

        $token = $user->createToken('mobile-device');

        $token->accessToken->forceFill([
            'created_at' => now()->subMinutes(61),
        ])->save();

        $this->withToken($token->plainTextToken)
            ->getJson('/v1/me')
            ->assertUnauthorized();

        Config::set('sanctum.expiration', $original);
    });

    test('session driver supports persistence', function () {
        $driver = config('session.driver');

        // Valid session drivers (array for tests, persistent for production)
        expect($driver)->toBeIn(['array', 'database', 'redis', 'cookie']);
    });
});
