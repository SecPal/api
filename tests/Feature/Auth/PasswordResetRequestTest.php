<?php

// SPDX-FileCopyrightText: 2025 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

/**
 * Feature tests for password reset request endpoint.
 *
 * @covers POST /api/v1/auth/password/reset-request
 */

uses(RefreshDatabase::class);

it('allows a user to request password reset with valid email', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $response = $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => 'test@example.com',
    ]);

    $response->assertOk()
        ->assertJson([
            'message' => 'Password reset email sent if account exists',
        ]);

    // Verify notification was sent
    // Notification::assertSentTo($user, PasswordResetNotification::class);
});

it('returns same response for non-existent email', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => 'nonexistent@example.com',
    ]);

    // Security: Same response to prevent email enumeration
    $response->assertOk()
        ->assertJson([
            'message' => 'Password reset email sent if account exists',
        ]);

    Notification::assertNothingSent();
});

it('requires email field', function () {
    $response = $this->postJson('/api/v1/auth/password/reset-request', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('requires valid email format', function () {
    $response = $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => 'invalid-email',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rate limits password reset requests', function () {
    $email = 'test@example.com';

    // Make 5 requests (should all be allowed)
    collect(range(1, 5))->each(fn () => $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => $email,
    ])->assertOk());

    // 6th request should be rate limited
    $response = $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => $email,
    ]);

    $response->assertStatus(429);
});
