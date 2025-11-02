<?php

// SPDX-FileCopyrightText: 2025 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Feature tests for password reset request endpoint.
 *
 * @covers POST /api/v1/auth/password/reset-request
 */
uses(RefreshDatabase::class);

it('allows a user to request password reset with valid email', function () {
    Mail::fake();

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

    // Verify email was queued (not sent immediately)
    Mail::assertQueued(PasswordResetMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});

it('returns same response for non-existent email', function () {
    Mail::fake();

    $response = $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => 'nonexistent@example.com',
    ]);

    // Security: Same response to prevent email enumeration
    $response->assertOk()
        ->assertJson([
            'message' => 'Password reset email sent if account exists',
        ]);

    Mail::assertNothingQueued();
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

it('email is queued not sent immediately', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => 'test@example.com',
    ]);

    // Verify email is queued (async)
    Mail::assertQueued(PasswordResetMail::class);

    // Verify email is NOT sent immediately
    Mail::assertNotSent(PasswordResetMail::class);
});

it('email contains valid reset token', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => 'test@example.com',
    ]);

    Mail::assertQueued(PasswordResetMail::class, function ($mail) use ($user) {
        // Email should have token and user data
        return $mail->hasTo($user->email) &&
               ! empty($mail->token) &&
               $mail->user->is($user);
    });
});

it('email contains secure reset url with encoded parameters', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'test+special@example.com',
    ]);

    $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => 'test+special@example.com',
    ]);

    Mail::assertQueued(PasswordResetMail::class, function ($mail) {
        // Build the mailable to access content
        $content = $mail->content();
        $resetUrl = $content->with['resetUrl'];

        // Verify URL is properly encoded
        expect($resetUrl)->toContain('auth/password-reset')
            ->and($resetUrl)->toContain('email=')
            ->and($resetUrl)->toContain('token=')
            // Special characters should be encoded
            ->and($resetUrl)->toContain('test%2Bspecial%40example.com');

        return true;
    });
});

it('email subject does not contain sensitive information', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => 'test@example.com',
    ]);

    Mail::assertQueued(PasswordResetMail::class, function ($mail) {
        $envelope = $mail->envelope();

        // Subject should NOT contain email, token, or other PII
        expect($envelope->subject)->toBe('Reset Your SecPal Password')
            ->and($envelope->subject)->not->toContain('test@example.com')
            ->and($envelope->subject)->not->toContain('token');

        return true;
    });
});

it('deletes old reset tokens before creating new one', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    // Request password reset first time
    $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => 'test@example.com',
    ]);

    $firstTokenCount = DB::table('password_reset_tokens')
        ->where('email', 'test@example.com')
        ->count();

    expect($firstTokenCount)->toBe(1);

    // Request again - should replace old token
    $this->postJson('/api/v1/auth/password/reset-request', [
        'email' => 'test@example.com',
    ]);

    $secondTokenCount = DB::table('password_reset_tokens')
        ->where('email', 'test@example.com')
        ->count();

    // Should still be 1 (old deleted, new inserted)
    expect($secondTokenCount)->toBe(1);

    // Should have sent 2 emails (total)
    Mail::assertQueued(PasswordResetMail::class, 2);
});
