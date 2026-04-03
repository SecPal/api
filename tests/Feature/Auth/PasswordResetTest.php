<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Activity;
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
        'remember_token' => 'persist-me',
    ]);

    $user->createToken('device-1');
    $user->createToken('device-2');

    DB::table('sessions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => base64_encode('password-reset-session'),
        'last_activity' => now()->timestamp,
    ]);

    $token = createPasswordResetToken($user);

    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'test@example.com',
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ]);

    $response->assertOk()
        ->assertJson([
            'message' => 'Password has been reset successfully',
        ]);

    $user->refresh();
    expect(Hash::check('NewSecurePassword123!', $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and($user->remember_token)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    $activity = Activity::query()
        ->where('log_name', 'authentication')
        ->where('description', 'User reset password and revoked active sessions')
        ->latest('id')
        ->first();

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->properties['event'])->toBe('password_reset');
});

it('rejects expired token', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $expiredToken = createPasswordResetToken($user, now()->subMinutes(61));

    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => $expiredToken,
        'email' => 'test@example.com',
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
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
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
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
        'password' => 'NewSecurePassword123!',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('validates centralized password requirements', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $token = createPasswordResetToken($user);

    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'test@example.com',
        'password' => 'alllowercase1234',
        'password_confirmation' => 'alllowercase1234',
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
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ])->assertOk();

    // Second attempt with same token fails
    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'test@example.com',
        'password' => 'AnotherSecurePassword456!',
        'password_confirmation' => 'AnotherSecurePassword456!',
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
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ])->assertStatus(400)); // Wrong token, but not rate limited

    // 6th request should be rate limited
    $response = $this->postJson('/v1/auth/password/reset', [
        'token' => 'wrong-token',
        'email' => 'test@example.com',
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ]);

    $response->assertStatus(429);
});
