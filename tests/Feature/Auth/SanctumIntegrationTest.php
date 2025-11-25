<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

describe('Integration: CORS and Security', function () {
    test('whitelisted origin receives CORS credentials header', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Request from whitelisted origin
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ])->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:5173');
    });

    test('OPTIONS preflight request succeeds for whitelisted origin', function () {
        $response = $this->call('OPTIONS', '/v1/auth/token', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type,X-XSRF-TOKEN',
        ]);

        $response->assertNoContent();
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:5173');
        expect($response->headers->get('Access-Control-Allow-Methods'))->toContain('POST');
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
    });

    test('CORS headers are present on authenticated requests', function () {
        $user = User::factory()->create();

        // Get CSRF token
        $this->get('/sanctum/csrf-cookie');

        // Login
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
        ])->postJson('/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertCreated();
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
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
    test('token expiration is configurable', function () {
        $expiration = config('sanctum.expiration');
        $lifetime = config('session.lifetime');

        // Sanctum token expiration (null = no expiration for personal access tokens)
        expect($expiration)->toBeNull(); // Expected for SPA auth

        // Session lifetime for web guard
        expect($lifetime)->toBeInt()->toBe(120); // 2 hours
    });

    test('session driver supports persistence', function () {
        $driver = config('session.driver');

        // Valid session drivers (array for tests, persistent for production)
        expect($driver)->toBeIn(['array', 'database', 'redis', 'cookie']);
    });
});
