<?php

// SPDX-FileCopyrightText: 2025 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for password reset confirmation endpoint.
 *
 * @covers POST /api/v1/auth/password/reset
 */
final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('old-password'),
        ]);

        // Create a password reset token
        $token = $this->createPasswordResetToken($user);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'test@example.com',
            'password' => 'new-secure-password-123',
            'password_confirmation' => 'new-secure-password-123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password has been reset successfully',
            ]);

        // Verify password was actually changed
        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password-123', $user->password));
    }

    public function test_rejects_expired_token(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $expiredToken = $this->createPasswordResetToken($user, now()->subMinutes(61));

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => $expiredToken,
            'email' => 'test@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Invalid or expired reset token',
            ]);
    }

    public function test_rejects_invalid_token(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => 'invalid-token-123',
            'email' => 'test@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Invalid or expired reset token',
            ]);
    }

    public function test_requires_all_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/password/reset', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token', 'email', 'password']);
    }

    public function test_requires_password_confirmation(): void
    {
        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => 'some-token',
            'email' => 'test@example.com',
            'password' => 'new-password-123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_validates_password_requirements(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $token = $this->createPasswordResetToken($user);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_token_can_only_be_used_once(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $token = $this->createPasswordResetToken($user);

        // First reset succeeds
        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'test@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(200);

        // Second attempt with same token fails
        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'test@example.com',
            'password' => 'another-password-456',
            'password_confirmation' => 'another-password-456',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Invalid or expired reset token',
            ]);
    }

    public function test_rate_limits_password_reset_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $token = $this->createPasswordResetToken($user);

        // Make 5 requests (should all be allowed)
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/password/reset', [
                'token' => 'wrong-token',
                'email' => 'test@example.com',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])->assertStatus(400); // Wrong token, but not rate limited
        }

        // 6th request should be rate limited
        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => 'wrong-token',
            'email' => 'test@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(429); // Too Many Requests
    }

    /**
     * Helper to create a password reset token for testing.
     */
    private function createPasswordResetToken(User $user, ?\DateTimeInterface $createdAt = null): string
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => $createdAt ?? now(),
        ]);

        return $token;
    }
}
