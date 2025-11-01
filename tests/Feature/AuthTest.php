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

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_name' => 'test-device',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
            ]);

        expect($response->json('user.email'))->toBe('test@example.com');
        expect($user->tokens()->count())->toBe(1);
    });

    test('token generation fails with invalid email', function () {
        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    test('token generation fails with invalid password', function () {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    test('token generation requires email', function () {
        $response = $this->postJson('/api/v1/auth/token', [
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    test('token generation requires password', function () {
        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    test('token generation uses default device name when not provided', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(201);
        expect($user->tokens()->first()->name)->toBe('api-client');
    });

    test('user can generate multiple tokens for different devices', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/api/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_name' => 'mobile',
        ])->assertStatus(201);

        $this->postJson('/api/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_name' => 'desktop',
        ])->assertStatus(201);

        expect($user->tokens()->count())->toBe(2);
        expect($user->tokens()->pluck('name')->toArray())->toContain('mobile', 'desktop');
    });
});

describe('Protected Endpoints', function () {
    test('protected endpoint requires authentication', function () {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
    });

    test('protected endpoint works with valid token', function () {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJson([
                'id' => $user->id,
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ]);
    });

    test('protected endpoint rejects invalid token', function () {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token-here')
            ->getJson('/api/v1/me');

        $response->assertStatus(401);
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
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
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
            ->postJson('/api/v1/auth/logout-all');

        $response->assertStatus(200)
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
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200);

        // Token deleted after logout
        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    test('logout requires authentication', function () {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    });

    test('logout-all requires authentication', function () {
        $response = $this->postJson('/api/v1/auth/logout-all');

        $response->assertStatus(401);
    });
});

describe('Token Security', function () {
    test('token does not expose sensitive user data', function () {
        $user = User::factory()->create([
            'password' => bcrypt('secret-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertStatus(201)
            ->assertJsonMissing(['password'])
            ->assertJsonMissing(['remember_token']);
    });

    test('protected endpoint does not expose sensitive user data', function () {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJsonMissing(['password'])
            ->assertJsonMissing(['remember_token']);
    });

    test('token is stored hashed in database', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/token', [
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
