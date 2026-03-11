<?php

// SPDX-FileCopyrightText: 2025 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Feature tests for password reset confirmation endpoint.
 *
 * @covers POST /v1/auth/password/reset
 */
uses(RefreshDatabase::class);

/**
 * Helper to create a password reset token for testing.
 */
function createPasswordResetToken(User $user, ?DateTimeInterface $createdAt = null): string
{
    $token = Str::random(64);

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make($token),
        'created_at' => $createdAt ?? now(),
    ]);

    return $token;
}

it('allows user to reset password with valid token', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('old-password'),
    ]);

    $token = createPasswordResetToken($user);

    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'test@example.com',
        'password' => 'new-secure-password-123',
        'password_confirmation' => 'new-secure-password-123',
    ]);

    $response->assertOk()
        ->assertJson([
            'message' => 'Password has been reset successfully',
        ]);

    $user->refresh();
    expect(Hash::check('new-secure-password-123', $user->password))->toBeTrue();
});

it('rejects expired token', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $expiredToken = createPasswordResetToken($user, now()->subMinutes(61));

    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => $expiredToken,
        'email' => 'test@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertStatus(400)
        ->assertJson([
            'message' => 'Invalid or expired reset token',
        ]);
});

it('rejects invalid token', function () {
    User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => 'invalid-token-123',
        'email' => 'test@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertStatus(400)
        ->assertJson([
            'message' => 'Invalid or expired reset token',
        ]);
});

it('requires all fields', function () {
    $response = $this->postJson('/v1/auth/password/reset', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['token', 'email', 'password']);
});

it('requires password confirmation', function () {
    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => 'some-token',
        'email' => 'test@example.com',
        'password' => 'new-password-123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('validates password requirements', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $token = createPasswordResetToken($user);

    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'test@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('ensures token can only be used once', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $token = createPasswordResetToken($user);

    // First reset succeeds
    $this->postJson('/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'test@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertOk();

    // Second attempt with same token fails
    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'test@example.com',
        'password' => 'another-password-456',
        'password_confirmation' => 'another-password-456',
    ]);

    $response->assertStatus(400)
        ->assertJson([
            'message' => 'Invalid or expired reset token',
        ]);
});

it('rate limits password reset attempts', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    // Make 5 requests (should all be allowed)
    collect(range(1, 5))->each(fn () => $this->postJson('/v1/auth/password/reset', [
        'token' => 'wrong-token',
        'email' => 'test@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertStatus(400)); // Wrong token, but not rate limited

    // 6th request should be rate limited
    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => 'wrong-token',
        'email' => 'test@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertStatus(429);
});
