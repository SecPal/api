<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

/**
 * Feature tests for password reset request endpoint.
 *
 * @covers POST /v1/auth/password/reset-request
 */
uses(RefreshDatabase::class);

it('allows a user to request password reset with valid email', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $response = $this->postJson('/v1/auth/password/reset-request', [
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

    $response = $this->postJson('/v1/auth/password/reset-request', [
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
    $response = $this->postJson('/v1/auth/password/reset-request', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('requires valid email format', function () {
    $response = $this->postJson('/v1/auth/password/reset-request', [
        'email' => 'invalid-email',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rate limits password reset requests', function () {
    $email = 'test@example.com';

    // Make 5 requests (should all be allowed)
    collect(range(1, 5))->each(fn () => $this->postJson('/v1/auth/password/reset-request', [
        'email' => $email,
    ])->assertOk());

    // 6th request should be rate limited
    $response = $this->postJson('/v1/auth/password/reset-request', [
        'email' => $email,
    ]);

    $response->assertStatus(429);
});

it('email is queued not sent immediately', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    $this->postJson('/v1/auth/password/reset-request', [
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

    $this->postJson('/v1/auth/password/reset-request', [
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
    config()->set('app.frontend_url', 'https://app.secpal.dev');

    $user = User::factory()->create([
        'email' => 'test+special@example.com',
    ]);

    $this->postJson('/v1/auth/password/reset-request', [
        'email' => 'test+special@example.com',
    ]);

    Mail::assertQueued(PasswordResetMail::class, function ($mail) {
        // Build the mailable to access content
        $content = $mail->content();
        $resetUrl = $content->with['resetUrl'];

        // Verify URL is properly encoded
        expect($resetUrl)->toStartWith('https://app.secpal.dev/auth/password-reset?')
            ->and($resetUrl)->toContain('auth/password-reset')
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

    $this->postJson('/v1/auth/password/reset-request', [
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
    $this->postJson('/v1/auth/password/reset-request', [
        'email' => 'test@example.com',
    ]);

    $firstTokenCount = DB::table('password_reset_tokens')
        ->where('email', 'test@example.com')
        ->count();

    expect($firstTokenCount)->toBe(1);

    // Request again - should replace old token
    $this->postJson('/v1/auth/password/reset-request', [
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

it('applies a minimum response delay regardless of account existence', function (bool $existingAccount, string $email) {
    Mail::fake();
    config()->set('auth.password_reset_min_response_time_ms', 30);

    if ($existingAccount) {
        User::factory()->create([
            'email' => $email,
        ]);
    }

    $startedAt = hrtime(true);

    $response = $this->postJson('/v1/auth/password/reset-request', [
        'email' => $email,
    ]);

    $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

    $response->assertOk()
        ->assertJson([
            'message' => 'Password reset email sent if account exists',
        ]);

    expect($elapsedMilliseconds)->toBeGreaterThanOrEqual(30.0);

    if ($existingAccount) {
        Mail::assertQueued(PasswordResetMail::class);

        return;
    }

    Mail::assertNothingQueued();
})->with([
    'existing account' => [true, 'existing@example.com'],
    'missing account' => [false, 'missing@example.com'],
]);

it('returns byte-identical response bodies for known and unknown emails', function () {
    Mail::fake();

    User::factory()->create([
        'email' => 'known@example.com',
    ]);

    $unknownResponse = $this->postJson('/v1/auth/password/reset-request', [
        'email' => 'unknown@example.com',
    ]);

    $knownResponse = $this->postJson('/v1/auth/password/reset-request', [
        'email' => 'known@example.com',
    ]);

    $unknownResponse->assertOk();
    $knownResponse->assertOk();

    expect($unknownResponse->getContent())->toBe($knownResponse->getContent());
});

it('returns equivalent externally observable headers for known and unknown emails', function () {
    Mail::fake();

    User::factory()->create([
        'email' => 'header-known@example.com',
    ]);

    // Headers that legitimately vary per response and would otherwise mask
    // genuine side-channel headers. Dates trivially differ; rate-limit
    // remaining decrements per call from the same bucket; Set-Cookie carries
    // session/CSRF tokens that rotate per request via Sanctum middleware.
    $varyingHeaders = ['date', 'x-ratelimit-remaining', 'set-cookie'];

    $normalize = static function (Symfony\Component\HttpFoundation\HeaderBag $bag) use ($varyingHeaders): array {
        $headers = array_change_key_case($bag->all(), CASE_LOWER);

        foreach ($varyingHeaders as $header) {
            unset($headers[$header]);
        }

        ksort($headers);

        return $headers;
    };

    $unknownResponse = $this->postJson('/v1/auth/password/reset-request', [
        'email' => 'header-unknown@example.com',
    ]);

    $knownResponse = $this->postJson('/v1/auth/password/reset-request', [
        'email' => 'header-known@example.com',
    ]);

    expect($normalize($unknownResponse->headers))
        ->toBe($normalize($knownResponse->headers));
});

it('invokes the password hasher unconditionally so timing variance does not leak account state', function (bool $existingAccount, string $email) {
    Mail::fake();
    config()->set('auth.password_reset_min_response_time_ms', 0);

    if ($existingAccount) {
        User::factory()->create([
            'email' => $email,
        ]);
    }

    // Replace the 'hash' service binding (the key the Hash facade resolves
    // through) with a container-managed Mockery mock. Using $this->instance()
    // rather than Hash::shouldReceive() ensures the mock is wired through the
    // same container binding that production code uses, satisfying the
    // "resolve framework-managed collaborators through the container" rule.
    // Clearing the facade's resolved-instance cache is required so that any
    // earlier resolution (e.g. during factory user creation) does not cause
    // the facade to keep serving the real HashManager instead of the mock.
    $this->instance('hash', Mockery::mock(HashManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('make')
            ->once()
            ->withArgs(fn ($value): bool => is_string($value) && strlen($value) === 64)
            ->andReturnUsing(fn (string $value): string => password_hash($value, PASSWORD_BCRYPT, ['cost' => 4]));
    }));
    Hash::clearResolvedInstances();

    $this->postJson('/v1/auth/password/reset-request', [
        'email' => $email,
    ])->assertOk();
})->with([
    'existing account' => [true, 'hash-existing@example.com'],
    'missing account' => [false, 'hash-missing@example.com'],
]);

it('keeps response-time variance between branches within a measured budget', function () {
    Mail::fake();

    // Disable the artificial floor so this exercises real work parity rather
    // than the safety pad in enforcePasswordResetMinimumResponseTime().
    config()->set('auth.password_reset_min_response_time_ms', 0);

    User::factory()->create([
        'email' => 'variance-known@example.com',
    ]);

    $iterationsPerBranch = 8;

    $measure = function (string $email) use ($iterationsPerBranch): float {
        $samples = [];

        for ($i = 0; $i < $iterationsPerBranch; $i++) {
            // Reset the throttle bucket so we can collect more than the
            // 5/60min cap and so neither branch ends up artificially delayed
            // by 429 responses.
            Cache::flush();

            $startedAt = hrtime(true);

            $response = test()->postJson('/v1/auth/password/reset-request', [
                'email' => $email,
            ]);

            $samples[] = (hrtime(true) - $startedAt) / 1_000_000;

            $response->assertOk();
        }

        sort($samples);

        return $samples[(int) floor(count($samples) / 2)];
    };

    $unknownMedian = $measure('variance-unknown@example.com');
    $knownMedian = $measure('variance-known@example.com');

    // The known branch performs one DB DELETE, one DB INSERT, and one
    // Mail::queue() in addition to the work shared with the unknown branch.
    // Mail::fake() short-circuits the queue write. Under BCRYPT_ROUNDS=4 the
    // hash step dominates noise from CI scheduling and DB jitter, so the
    // residual delta should stay well inside the budget below.
    expect(abs($knownMedian - $unknownMedian))->toBeLessThan(50.0);
});

it('does not write password reset side effects for unknown emails', function () {
    Mail::fake();
    config()->set('auth.password_reset_min_response_time_ms', 0);

    $this->postJson('/v1/auth/password/reset-request', [
        'email' => 'queue-oracle-missing@example.com',
    ])->assertOk();

    Mail::assertNothingQueued();

    expect(
        DB::table('password_reset_tokens')
            ->where('email', 'queue-oracle-missing@example.com')
            ->count()
    )->toBe(0);
});

it('preserves the generic response when the mail queue write fails so a queue outage cannot enumerate accounts', function () {
    config()->set('auth.password_reset_min_response_time_ms', 0);

    $user = User::factory()->create([
        'email' => 'queue-fail@example.com',
    ]);

    Mail::shouldReceive('to')
        ->once()
        ->andThrow(new RuntimeException('Queue unavailable'));

    $this->postJson('/v1/auth/password/reset-request', [
        'email' => 'queue-fail@example.com',
    ])->assertOk()->assertJson([
        'message' => 'Password reset email sent if account exists',
    ]);

    // The token row is committed before the mail enqueue runs. Keeping the
    // token persisted is the intended trade-off: a transient queue outage
    // leaves the user able to retry without re-requesting a reset, and
    // avoids the queue-vs-transaction races that would arise if the mail
    // dispatch were placed inside the DB transaction (out-of-process queue
    // backends with `after_commit=false` could otherwise deliver an email
    // for a token that was not yet — or never — visible to the worker).
    expect(
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->count()
    )->toBe(1);
});

it('preserves the generic response when the token write fails so a database outage cannot enumerate accounts', function () {
    config()->set('auth.password_reset_min_response_time_ms', 0);
    Mail::fake();

    User::factory()->create([
        'email' => 'db-fail@example.com',
    ]);

    // Drop the reset-token table so the DB::transaction() body fails with a
    // real database exception. The user-lookup SELECT above is on `users`
    // and is unaffected.
    Schema::drop('password_reset_tokens');

    $this->postJson('/v1/auth/password/reset-request', [
        'email' => 'db-fail@example.com',
    ])->assertOk()->assertJson([
        'message' => 'Password reset email sent if account exists',
    ]);

    Mail::assertNothingQueued();
});
