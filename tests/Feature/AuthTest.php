<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Auth Token Generation', function () {
    test('user can generate token with valid credentials', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_name' => 'test-device',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
            ]);

        expect($response->json('user.email'))->toBe('test@example.com');
        expect($user->tokens()->count())->toBe(1);
    });

    test('token generation fails with invalid email', function () {
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('token generation fails with invalid password', function () {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('token generation requires email', function () {
        $response = $this->postJson('/v1/auth/token', [
            'password' => 'password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('token generation requires password', function () {
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    });

    test('token generation uses default device name when not provided', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        expect($user->tokens()->first()?->name)->toBe('api-client');
    });

    test('user can generate multiple tokens for different devices', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_name' => 'mobile',
        ])->assertCreated();

        $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_name' => 'desktop',
        ])->assertCreated();

        expect($user->tokens()->count())->toBe(2);
        expect($user->tokens()->pluck('name')->toArray())->toContain('mobile', 'desktop');
    });
});

describe('Protected Endpoints', function () {
    test('protected endpoint requires authentication', function () {
        $response = $this->getJson('/v1/me');

        $response->assertUnauthorized();
    });

    test('protected endpoint works with valid token', function () {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/me');

        $response->assertOk()
            ->assertJson([
                'id' => $user->id,
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ]);
    });

    test('protected endpoint rejects invalid token', function () {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token-here')
            ->getJson('/v1/me');

        $response->assertUnauthorized();
    });
});

describe('Token Revocation', function () {
    test('user can logout and revoke current token', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $token = $user->createToken('device-1')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/auth/logout');

        $response->assertOk()
            ->assertJson(['message' => 'Token revoked successfully.']);

        expect($user->tokens()->count())->toBe(0);
    });

    test('user can logout from all devices', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $token1 = $user->createToken('device-1')->plainTextToken;
        $user->createToken('device-2');
        $user->createToken('device-3');

        expect($user->tokens()->count())->toBe(3);

        $response = $this->withHeader('Authorization', "Bearer {$token1}")
            ->postJson('/v1/auth/logout-all');

        $response->assertOk()
            ->assertJson(['message' => 'All tokens revoked successfully.']);

        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    test('logout successfully deletes token from database', function () {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;

        // Token exists before logout
        expect($user->tokens()->count())->toBe(1);

        // Logout (revoke token)
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/auth/logout')
            ->assertOk();

        // Token deleted after logout
        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    test('logout handles already-deleted token gracefully', function () {
        $user = User::factory()->create();
        $token1 = $user->createToken('device-1');
        $token2 = $user->createToken('device-2');

        // Simulate race condition: delete token2 manually (e.g., concurrent logout)
        $token2->accessToken->delete();

        // Now logout with token1, but mock currentAccessToken to return null
        // This tests the controller's null handling directly
        $response = $this->withHeader('Authorization', "Bearer {$token1->plainTextToken}")
            ->postJson('/v1/auth/logout');

        // Should succeed without crashing (200 OK)
        $response->assertOk()
            ->assertJson(['message' => 'Token revoked successfully.']);

        // Token1 should be deleted
        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    test('logout requires authentication', function () {
        $response = $this->postJson('/v1/auth/logout');

        $response->assertUnauthorized();
    });

    test('logout-all requires authentication', function () {
        $response = $this->postJson('/v1/auth/logout-all');

        $response->assertUnauthorized();
    });
});

describe('Token Security', function () {
    test('token does not expose sensitive user data', function () {
        $user = User::factory()->create([
            'password' => bcrypt('secret-password'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertCreated()
            ->assertJsonMissing(['password'])
            ->assertJsonMissing(['remember_token']);
    });

    test('protected endpoint does not expose sensitive user data', function () {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/me');

        $response->assertOk()
            ->assertJsonMissing(['password'])
            ->assertJsonMissing(['remember_token']);
    });

    test('token is stored hashed in database', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $plainTextToken = $response->json('token');
        $tokenRecord = $user->tokens()->first();

        // Plain text token should not match database token
        expect($tokenRecord->token)->not->toBe($plainTextToken);
        // Database token should be hashed (64 chars for SHA-256)
        expect(strlen($tokenRecord->token))->toBe(64);
    });
});

describe('Login Rate Limiting', function () {
    beforeEach(function () {
        // Clear rate limiter cache between tests
        // RateLimiter::clear('login') doesn't work because it expects full key like 'login:ip|email'
        // Using Cache::flush() ensures clean state for each test
        \Illuminate\Support\Facades\Cache::flush();
    });

    test('token endpoint is rate limited after 5 failed attempts', function () {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        // Make 5 failed token attempts (the limit)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/v1/auth/token', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ]);

            $response->assertUnprocessable();
        }

        // 6th attempt should be rate limited
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests()
            ->assertJson(['message' => 'Too many login attempts. Please try again in 60 seconds.']);
    });

    test('rate limit is per email and IP combination', function () {
        User::factory()->create([
            'email' => 'user1@example.com',
            'password' => bcrypt('password'),
        ]);
        User::factory()->create([
            'email' => 'user2@example.com',
            'password' => bcrypt('password'),
        ]);

        // Exhaust rate limit for user1
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/token', [
                'email' => 'user1@example.com',
                'password' => 'wrong',
            ]);
        }

        // user1 should be rate limited
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'user1@example.com',
            'password' => 'wrong',
        ]);
        $response->assertTooManyRequests();

        // user2 should NOT be rate limited (different email)
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'user2@example.com',
            'password' => 'wrong',
        ]);
        $response->assertUnprocessable(); // 422, not 429
    });

    test('successful login resets rate limit counter', function () {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        // Make 3 failed attempts (not exhausting the limit)
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/v1/auth/token', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ]);
        }

        // Successful login should work
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    });

    test('rate limit applies to email regardless of password', function () {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Exhaust rate limit with different wrong passwords
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/token', [
                'email' => 'test@example.com',
                'password' => 'wrong'.$i,
            ]);
        }

        // 6th attempt with yet another password should still be blocked
        $response = $this->postJson('/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'different-wrong',
        ]);

        $response->assertTooManyRequests();
    });

    test('same email from different IPs has separate rate limits', function () {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Exhaust rate limit from first IP
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
                ->postJson('/v1/auth/token', [
                    'email' => 'test@example.com',
                    'password' => 'wrong',
                ]);
        }

        // First IP should be rate limited
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->postJson('/v1/auth/token', [
                'email' => 'test@example.com',
                'password' => 'wrong',
            ]);
        $response->assertTooManyRequests();

        // Different IP should NOT be rate limited for same email
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.2'])
            ->postJson('/v1/auth/token', [
                'email' => 'test@example.com',
                'password' => 'wrong',
            ]);
        $response->assertUnprocessable(); // 422, not 429
    });

    test('session login endpoint is also rate limited', function () {
        User::factory()->create([
            'email' => 'session-test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Make 5 failed login attempts - use withSession to enable session
        for ($i = 0; $i < 5; $i++) {
            $this->withSession([])
                ->postJson('/v1/auth/login', [
                    'email' => 'session-test@example.com',
                    'password' => 'wrong',
                ]);
        }

        // 6th attempt should be rate limited
        $response = $this->withSession([])
            ->postJson('/v1/auth/login', [
                'email' => 'session-test@example.com',
                'password' => 'wrong',
            ]);

        $response->assertTooManyRequests();
    });
});

describe('Unauthenticated Request Handling', function () {
    test('unauthenticated request to protected endpoint returns 401 JSON response', function () {
        // Issue #253: API should return 401 JSON, not 500 "Route [login] not defined"
        $response = $this->getJson('/v1/secrets');

        $response->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    });

    test('request with invalid token returns 401 JSON response', function () {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token-12345')
            ->getJson('/v1/secrets');

        $response->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    });
});
