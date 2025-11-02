<?php

// SPDX-FileCopyrightText: 2025 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Feature tests for password reset request endpoint.
 *
 * @covers POST /api/v1/auth/password/reset-request
 */
final class PasswordResetRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_request_password_reset_with_valid_email(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/password/reset-request', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password reset email sent if account exists',
            ]);

        // Verify notification was sent
        // Notification::assertSentTo($user, PasswordResetNotification::class);
    }

    public function test_returns_same_response_for_non_existent_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/password/reset-request', [
            'email' => 'nonexistent@example.com',
        ]);

        // Security: Same response to prevent email enumeration
        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password reset email sent if account exists',
            ]);

        Notification::assertNothingSent();
    }

    public function test_requires_email_field(): void
    {
        $response = $this->postJson('/api/v1/auth/password/reset-request', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_requires_valid_email_format(): void
    {
        $response = $this->postJson('/api/v1/auth/password/reset-request', [
            'email' => 'invalid-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_rate_limits_password_reset_requests(): void
    {
        $email = 'test@example.com';

        // Make multiple requests
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/v1/auth/password/reset-request', [
                'email' => $email,
            ]);

            if ($i < 5) {
                $response->assertStatus(200);
            } else {
                // 6th request should be rate limited
                $response->assertStatus(429);
            }
        }
    }
}
