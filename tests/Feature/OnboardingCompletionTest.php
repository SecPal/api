<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeOnboardingToken;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
    $this->withHeaders(spaCsrfHeaders($this));
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('completes onboarding with valid token', function () {
    Notification::fake();

    // Arrange: Create pre-contract employee with user
    /** @var User $user */
    $user = User::factory()->unverified()->create([
        'password' => Hash::make('temporary-password'), // Will be replaced during onboarding
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John', // Use realistic names to avoid validation issues
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Act: Complete onboarding with same names (no change)
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
    ]);

    // Assert: Response is successful
    $response->assertOk()
        ->assertJsonStructure([
            'message',
            'data' => [
                'user' => ['id', 'email', 'name', 'email_verified'],
                'employee' => ['id', 'first_name', 'last_name', 'status'],
            ],
        ])
        ->assertJsonMissing(['token']); // Session-based auth, no token in response

    // Assert: Employee name updated
    $employee->refresh();
    expect($employee->first_name)->toBe('John')
        ->and($employee->last_name)->toBe('Doe')
        ->and($employee->onboarding_started_at)->not->toBeNull()
        ->and($employee->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED);

    // Assert: Password set on user
    $user = $employee->user;
    expect($user)->not->toBeNull();
    expect(Hash::check('SecurePassword123!', $user->password))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();

    // Assert: Token marked as completed
    $tokenModel = $tokenData['model'];
    $tokenModel->refresh();
    expect($tokenModel->completed_at)->not->toBeNull()
        ->and($tokenModel->completed_from_ip)->not->toBeNull()
        ->and($tokenModel->completed_user_agent)->not->toBeNull();
});

test('completes onboarding by syncing and verifying the invited employee email on the linked user account', function () {
    Notification::fake();

    /** @var User $user */
    $user = User::factory()->unverified()->create([
        'email' => 'stale-user@secpal.dev',
        'password' => Hash::make('temporary-password'),
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Email',
        'last_name' => 'Mismatch',
        'date_of_birth' => '1988-07-22',
        'email' => 'invited-employee@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);

    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $employee->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'Email',
        'last_name' => 'Mismatch',
        'date_of_birth' => '1988-07-22',
    ])->assertOk()
        ->assertJsonPath('data.user.email', $employee->email)
        ->assertJsonPath('data.user.email_verified', true);

    $user->refresh();
    expect($user->email)->toBe($employee->email)
        ->and($user->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});

test('dispatches verified event when onboarding completion verifies the user email', function () {
    Event::fake([Verified::class]);

    /** @var User $user */
    $user = User::factory()->unverified()->create([
        'password' => Hash::make('temporary-password'),
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Event',
        'last_name' => 'Dispatch',
        'date_of_birth' => '1992-02-29',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);

    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $employee->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'Event',
        'last_name' => 'Dispatch',
        'date_of_birth' => '1992-02-29',
    ])->assertOk();

    $user->refresh();
    Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($user));
});

test('rejects invalid token', function () {
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => 'invalid-token-that-does-not-exist',
        'email' => 'test@secpal.dev',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('rejects expired token', function () {
    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'test@secpal.dev',
        'date_of_birth' => '1990-01-01',
    ]);
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Expire token
    $tokenData['model']->update(['expires_at' => now()->subDay()]);

    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@secpal.dev',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('rejects already used token', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Complete onboarding once
    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ])->assertOk();

    // Try to use same token again
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => $user->email,
        'password' => 'DifferentPassword456!',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('rejects onboarding for non-pre-contract employee', function () {
    // Create employee with status other than PRE_CONTRACT
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => Employee::STATUS_ACTIVE,
        'email' => 'test@secpal.dev',
        'date_of_birth' => '1990-01-01',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@secpal.dev',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Onboarding is only available for pre-contract employees.',
        ]);
});

test('validates required fields', function () {
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['token', 'email', 'password', 'first_name', 'last_name', 'date_of_birth']);
});

test('rejects date_of_birth that is not a Y-m-d date string', function () {
    /** @var User $user */
    $user = User::factory()->create(['email' => 'test@secpal.dev']);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'test@secpal.dev',
        'date_of_birth' => '1990-01-01',
        'user_id' => $user->id,
    ]);
    $tokenData = EmployeeOnboardingToken::generate($employee);

    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => 'test@secpal.dev',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '01.01.1990', // wrong format
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['date_of_birth']);
});

test('rejects date_of_birth in the future', function () {
    /** @var User $user */
    $user = User::factory()->create(['email' => 'test@secpal.dev']);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'test@secpal.dev',
        'date_of_birth' => '1990-01-01',
        'user_id' => $user->id,
    ]);
    $tokenData = EmployeeOnboardingToken::generate($employee);

    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => 'test@secpal.dev',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => now()->addYear()->format('Y-m-d'),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['date_of_birth']);
});

test('validates password strength', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'test@secpal.dev',
        'date_of_birth' => '1990-01-01',
        'user_id' => $user->id,
    ]);
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@secpal.dev',
        'password' => 'weak', // Too weak password
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('rate limits onboarding attempts', function () {
    // Make 4 requests (limit is 3 per 10 minutes)
    for ($i = 0; $i < 4; $i++) {
        $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
            'token' => 'invalid-token',
            'email' => 'test@secpal.dev',
            'password' => 'SecurePassword123!',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'date_of_birth' => '1990-01-01',
        ]);
    }

    // 4th request should be rate limited
    $response->assertStatus(429)
        ->assertJson([
            'message' => 'Too many onboarding attempts. Please try again later.',
        ]);
});

test('validation throttle bucket does not block onboarding completion for the same invitee', function () {
    for ($i = 0; $i < 4; $i++) {
        $response = $this->getJson('/v1/onboarding/validate-token?token=invalid-token&email=separate@secpal.dev');
    }

    $response->assertStatus(429);

    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'separate@secpal.dev',
        'password' => Hash::make('temporary-password'),
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Separate',
        'last_name' => 'Bucket',
        'date_of_birth' => '1985-12-31',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'Separate',
        'last_name' => 'Bucket',
        'date_of_birth' => '1985-12-31',
    ])->assertOk();
});

// ===== SECURITY TESTS: Email Validation =====

test('SECURITY: rejects valid token with wrong email', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'correct@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'correct@secpal.dev',
        'date_of_birth' => '1990-01-01',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Attacker tries to use valid token with different email
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'attacker@secpal.dev', // Wrong email!
        'password' => 'SecurePassword123!',
        'first_name' => 'Hacker',
        'last_name' => 'McHackface',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);

    // Verify employee was NOT modified
    $employee->refresh();
    expect($employee->first_name)->not->toBe('Hacker');
});

test('SECURITY: validates email case-sensitively', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'test@secpal.dev',
        'date_of_birth' => '1990-01-01',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Try with uppercase email
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'TEST@SECPAL.DEV', // Wrong case
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('SECURITY: prevents token hijacking scenario', function () {
    // Scenario: Attacker intercepts token for victim@secpal.dev
    // Tries to use it to create account for attacker@secpal.dev

    /** @var User $victimUser */
    $victimUser = User::factory()->create([
        'email' => 'victim@secpal.dev',
    ]);

    /** @var Employee $victimEmployee */
    $victimEmployee = Employee::factory()->preContract()->create([
        'first_name' => 'Victim',
        'last_name' => 'User',
        'date_of_birth' => '1990-01-01',
        'email' => 'victim@secpal.dev',
        'user_id' => $victimUser->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($victimEmployee);
    $interceptedToken = $tokenData['plain'];

    // Attacker tries to complete onboarding with their own email
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $interceptedToken,
        'email' => 'attacker@secpal.dev',
        'password' => 'AttackerPassword123!',
        'first_name' => 'Attacker',
        'last_name' => 'McEvil',
        'date_of_birth' => '1990-01-01',
    ]);

    // Should be rejected
    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);

    // Verify victim's data was NOT compromised
    $victimEmployee->refresh();
    expect($victimEmployee->first_name)->toBe('Victim');
    expect($victimEmployee->last_name)->toBe('User');
    expect($victimEmployee->email)->toBe('victim@secpal.dev');

    // Verify victim's password was NOT changed
    $victimUser->refresh();
    expect(Hash::check('AttackerPassword123!', $victimUser->password))->toBeFalse();
});

// ===== SECURITY TESTS: Date of Birth Verification =====

test('SECURITY: rejects wrong date of birth with generic identity-verification error', function () {
    Illuminate\Support\Facades\Mail::fake();

    /** @var User $user */
    $user = User::factory()->unverified()->create([
        'password' => Hash::make('temporary-password'),
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);

    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1991-04-15', // off by one year
    ]);

    // Generic 422 without an `errors` payload so attackers cannot tell which field
    // is wrong (DOB vs name) — and so the throttle bucket counts the attempt.
    $response->assertStatus(422)
        ->assertJsonStructure(['message'])
        ->assertJsonMissing(['errors']);

    expect($response->json('message'))
        ->toBe('We could not verify your identity with the details provided. For security reasons this onboarding link has been deactivated. Please contact HR for a new invitation.');

    // Employee record must be untouched (still pre-contract, no name update)
    $employee->refresh();
    expect($employee->first_name)->toBe('John')
        ->and($employee->last_name)->toBe('Doe')
        ->and($employee->onboarding_started_at)->toBeNull()
        ->and($employee->onboarding_workflow_status)->not->toBe(Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED);

    // Password must NOT have been changed
    $user->refresh();
    expect(Hash::check('SecurePassword123!', $user->password))->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    // Token must be burned immediately after a failed identity proof.
    $tokenModel = $tokenData['model'];
    $tokenModel->refresh();
    expect($tokenModel->invalidated_at)->not->toBeNull()
        ->and($tokenModel->invalidation_reason)->toBe('identity_verification_failed')
        ->and($tokenModel->completed_at)->toBeNull()
        ->and($tokenModel->isValid())->toBeFalse();

    // HR must NOT be notified — onboarding never reached the name-change pipeline
    Illuminate\Support\Facades\Mail::assertNothingQueued();
});

test('SECURITY: DOB-mismatch attempts count toward the onboarding-complete rate limit', function () {
    /** @var User $user */
    $user = User::factory()->unverified()->create();

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);

    $payloadWithWrongDob = [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ];

    // Limit is 3 per 10 minutes. We must NOT be able to probe DOBs at will.
    $response = null;
    for ($i = 0; $i < 4; $i++) {
        $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
            ...$payloadWithWrongDob,
            'date_of_birth' => sprintf('1990-04-%02d', $i + 16), // never matches
        ]);
    }

    $response->assertStatus(429)
        ->assertJson([
            'message' => 'Too many onboarding attempts. Please try again later.',
        ]);
});

test('SECURITY: a missing stored date of birth blocks onboarding without burning the token', function () {
    Illuminate\Support\Facades\Mail::fake();

    /** @var User $user */
    $user = User::factory()->unverified()->create([
        'password' => Hash::make('temporary-password'),
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => null,
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $tokenModel = $tokenData['model'];

    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
    ]);

    $response->assertStatus(409)
        ->assertJson([
            'message' => 'Onboarding cannot be completed because your HR record is incomplete. Please contact HR before retrying this onboarding link.',
        ]);

    $employee->refresh();
    expect($employee->first_name)->toBe('John')
        ->and($employee->last_name)->toBe('Doe')
        ->and($employee->onboarding_started_at)->toBeNull()
        ->and($employee->onboarding_workflow_status)->not->toBe(Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED);

    $user->refresh();
    expect(Hash::check('SecurePassword123!', $user->password))->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    $tokenModel->refresh();
    expect($tokenModel->invalidated_at)->toBeNull()
        ->and($tokenModel->completed_at)->toBeNull()
        ->and($tokenModel->isValid())->toBeTrue();

    Illuminate\Support\Facades\Mail::assertNothingQueued();
});

// ===== SECURITY TESTS: Single-shot identity-proof policy =====
//
// Policy (see issue: onboarding-verify-dob):
//   A wrong date of birth (or a name too different from the HR record) is
//   treated as a failed identity proof and BURNS the magic link immediately.
//   Anyone in possession of the link who cannot also prove they know their
//   own DOB/name is, by definition, not the intended recipient — so no
//   second guess is allowed. A new invitation must be issued by HR.

test('SECURITY: a wrong date of birth invalidates the token immediately (single-shot)', function () {
    /** @var User $user */
    $user = User::factory()->unverified()->create([
        'password' => Hash::make('temporary-password'),
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $tokenModel = $tokenData['model'];

    // First attempt: wrong DOB → generic 422
    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1991-04-15',
    ])->assertStatus(422);

    // Token must be permanently invalidated (audit trail captured)
    $tokenModel->refresh();
    expect($tokenModel->invalidated_at)->not->toBeNull()
        ->and($tokenModel->invalidation_reason)->toBe('identity_verification_failed')
        ->and($tokenModel->invalidated_from_ip)->not->toBeNull()
        ->and($tokenModel->invalidated_user_agent)->not->toBeNull()
        // The token must NOT be marked as completed: this was a rejection,
        // not a successful onboarding.
        ->and($tokenModel->completed_at)->toBeNull()
        ->and($tokenModel->isValid())->toBeFalse();

    // Password must NOT have been saved on the user account
    $user->refresh();
    expect(Hash::check('SecurePassword123!', $user->password))->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    // Employee record must be untouched
    $employee->refresh();
    expect($employee->onboarding_started_at)->toBeNull()
        ->and($employee->onboarding_workflow_status)
        ->not->toBe(Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED);
});

test('SECURITY: a retry on a burned token (even with correct details) is rejected as invalid link', function () {
    /** @var User $user */
    $user = User::factory()->unverified()->create([
        'password' => Hash::make('temporary-password'),
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);

    // First attempt: wrong DOB burns the token.
    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1991-04-15',
    ])->assertStatus(422);

    // Second attempt with the SAME token and now-correct DOB must be refused as
    // "invalid or expired" — exactly like a stale token, leaking nothing extra.
    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
    ])->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);

    $user->refresh();
    expect(Hash::check('SecurePassword123!', $user->password))->toBeFalse();
});

test('SECURITY: a too-different name also invalidates the token immediately', function () {
    /** @var User $user */
    $user = User::factory()->unverified()->create([
        'password' => Hash::make('temporary-password'),
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Hans',
        'last_name' => 'Müller',
        'date_of_birth' => '1985-06-10',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $tokenModel = $tokenData['model'];

    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'Maria', // major change (<50% similar)
        'last_name' => 'Schmidt',
        'date_of_birth' => '1985-06-10',
    ])->assertStatus(422);

    $tokenModel->refresh();
    expect($tokenModel->invalidated_at)->not->toBeNull()
        ->and($tokenModel->invalidation_reason)->toBe('identity_verification_failed');

    $user->refresh();
    expect(Hash::check('SecurePassword123!', $user->password))->toBeFalse();
});

test('SECURITY: the identity-mismatch event is written to the activity log', function () {
    /** @var User $user */
    $user = User::factory()->unverified()->create();

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);

    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1991-04-15',
    ])->assertStatus(422);

    $activity = Spatie\Activitylog\Models\Activity::where('subject_id', $employee->id)
        ->where('log_name', 'employee-onboarding')
        ->where('description', 'Onboarding link invalidated due to failed identity verification')
        ->first();

    expect($activity)->not->toBeNull();
    $properties = $activity->properties;
    expect($properties->get('reason'))->toBe('identity_verification_failed')
        ->and($properties->get('date_of_birth_matched'))->toBeFalse()
        ->and($properties->get('ip'))->not->toBeNull()
        ->and($properties->get('user_agent'))->not->toBeNull();
});

test('SECURITY: shape-validation failures (weak password) do NOT burn the token', function () {
    // Weak password is a recoverable user error, not an identity-proof failure.
    // The invitee must be able to retry with a stronger password.
    /** @var User $user */
    $user = User::factory()->unverified()->create();

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $tokenModel = $tokenData['model'];

    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'weak',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['password']);

    $tokenModel->refresh();
    expect($tokenModel->invalidated_at)->toBeNull()
        ->and($tokenModel->isValid())->toBeTrue();
});

test('SECURITY: malformed date_of_birth does NOT burn the token', function () {
    /** @var User $user */
    $user = User::factory()->unverified()->create();

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'date_of_birth' => '1990-04-15',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $tokenModel = $tokenData['model'];

    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '15.04.1990', // wrong format, caught by date_format rule
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['date_of_birth']);

    $tokenModel->refresh();
    expect($tokenModel->invalidated_at)->toBeNull()
        ->and($tokenModel->isValid())->toBeTrue();
});

test('SECURITY: pre-contract status mismatch does NOT burn the token', function () {
    /** @var User $user */
    $user = User::factory()->create(['email' => 'active@secpal.dev']);

    /** @var Employee $employee */
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => Employee::STATUS_ACTIVE, // not pre_contract
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $tokenModel = $tokenData['model'];

    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
    ])->assertStatus(403);

    // Lifecycle-state problems are not attacks; we don't punish HR by burning
    // their (legitimate) magic link. Re-issuing wouldn't help anyway.
    $tokenModel->refresh();
    expect($tokenModel->invalidated_at)->toBeNull()
        ->and($tokenModel->isValid())->toBeTrue();
});

test('SECURITY: a successful onboarding does NOT mark the token as invalidated', function () {
    /** @var User $user */
    $user = User::factory()->unverified()->create();

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $tokenModel = $tokenData['model'];

    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-04-15',
    ])->assertOk();

    $tokenModel->refresh();
    expect($tokenModel->invalidated_at)->toBeNull()
        ->and($tokenModel->completed_at)->not->toBeNull();
});

test('SECURITY: rejects too-different name with generic identity-verification error (no field oracle)', function () {
    Illuminate\Support\Facades\Mail::fake();

    /** @var User $user */
    $user = User::factory()->unverified()->create([
        'password' => Hash::make('temporary-password'),
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Hans',
        'last_name' => 'Müller',
        'date_of_birth' => '1985-06-10',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);

    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'Maria', // major change (<50% similar) — would have leaked field error before
        'last_name' => 'Schmidt',
        'date_of_birth' => '1985-06-10', // correct DOB
    ]);

    $response->assertStatus(422)
        ->assertJsonMissing(['errors'])
        ->assertJsonStructure(['message']);

    expect($response->json('message'))
        ->toBe('We could not verify your identity with the details provided. For security reasons this onboarding link has been deactivated. Please contact HR for a new invitation.');

    // Employee record must be untouched
    $employee->refresh();
    expect($employee->first_name)->toBe('Hans')
        ->and($employee->last_name)->toBe('Müller');

    $user->refresh();
    expect(Hash::check('SecurePassword123!', $user->password))->toBeFalse();

    Illuminate\Support\Facades\Mail::assertNothingQueued();
});

test('SECURITY: missing HR date_of_birth returns conflict without burning the token', function () {
    /** @var User $user */
    $user = User::factory()->unverified()->create();

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => null,
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $tokenModel = $tokenData['model'];

    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ])->assertStatus(409)
        ->assertJson([
            'message' => 'Onboarding cannot be completed because your HR record is incomplete. Please contact HR before retrying this onboarding link.',
        ]);

    $tokenModel->refresh();
    expect($tokenModel->invalidated_at)->toBeNull()
        ->and($tokenModel->completed_at)->toBeNull()
        ->and($tokenModel->isValid())->toBeTrue();
});

test('SECURITY: repeated 409 (missing HR DOB) counts toward the rate limit', function () {
    // 409 confirms that the token+email pair is valid. Without rate-limiting it, an
    // attacker could use repeated 409s to confirm a live token without burning a slot.
    /** @var User $user */
    $user = User::factory()->unverified()->create();

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'date_of_birth' => null,
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);

    $payload = [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ];

    // Limit is 3 per 10 minutes. The first three return 409; the fourth must be 429.
    $response = null;
    for ($i = 0; $i < 4; $i++) {
        $response = $this->withSession([])->postJson('/v1/onboarding/complete', $payload);
    }

    $response->assertStatus(429)
        ->assertJson([
            'message' => 'Too many onboarding attempts. Please try again later.',
        ]);
});

test('SECURITY: stored DOB formatting differences do not burn a valid token', function () {
    /** @var User $user */
    $user = User::factory()->unverified()->create();

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-1-1',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $tokenModel = $tokenData['model'];

    $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $tokenData['plain'],
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ])->assertOk();

    $tokenModel->refresh();
    expect($tokenModel->invalidated_at)->toBeNull()
        ->and($tokenModel->completed_at)->not->toBeNull();
});

test('logs name changes with enhanced activity log', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'OldFirst',
        'last_name' => 'OldLast',
        'date_of_birth' => '1990-01-01',
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Complete onboarding with names that pass the similarity check (medium severity).
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@secpal.dev',
        'password' => 'SecurePassword123!',
        'first_name' => 'NewFirst',
        'last_name' => 'NewLast',
        'date_of_birth' => '1990-01-01',
    ]);

    // NewFirst vs OldFirst / NewLast vs OldLast share the 'First'/'Last' suffix
    // and are ~57% similar via Levenshtein, which falls in the "medium" range.
    $response->assertOk();

    // Verify activity log was created
    $activity = Spatie\Activitylog\Models\Activity::where('subject_id', $employee->id)
        ->where('log_name', 'employee-onboarding')
        ->where('description', 'Employee name changed during onboarding completion')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->properties)->toHaveKey('old_first_name', 'OldFirst');
    expect($activity->properties)->toHaveKey('new_first_name', 'NewFirst');
    expect($activity->properties)->toHaveKey('old_last_name', 'OldLast');
    expect($activity->properties)->toHaveKey('new_last_name', 'NewLast');
    expect($activity->properties)->toHaveKey('ip');
    expect($activity->properties)->toHaveKey('user_agent');
});

test('does not log activity if names unchanged', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Complete onboarding with same names
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@secpal.dev',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertOk();

    // Verify NO activity log for name change was created
    $activity = Spatie\Activitylog\Models\Activity::where('subject_id', $employee->id)
        ->where('log_name', 'employee-onboarding')
        ->where('description', 'Employee name changed during onboarding completion')
        ->first();

    expect($activity)->toBeNull();
});

// ============================================================================
// Name Change Validation Tests (Hybrid Approach: Similarity + HR Notification)
// ============================================================================

test('allows minor name correction (typo, >80% similar)', function () {
    /** @var User $user */
    $user = User::factory()->create(['email' => 'test@secpal.dev']);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Hanns', // Typo
        'last_name' => 'Mueller', // Alternate spelling
        'date_of_birth' => '1985-03-12',
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Minor corrections should be allowed
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@secpal.dev',
        'password' => 'SecurePassword123!',
        'first_name' => 'Hans', // Corrected
        'last_name' => 'Müller', // Corrected with umlaut
        'date_of_birth' => '1985-03-12',
    ]);

    $response->assertOk();

    // Verify name was updated
    $employee->refresh();
    expect($employee->first_name)->toBe('Hans');
    expect($employee->last_name)->toBe('Müller');

    // Verify activity log contains severity info
    $activity = Spatie\Activitylog\Models\Activity::where('subject_id', $employee->id)
        ->where('log_name', 'employee-onboarding')
        ->where('description', 'Employee name changed during onboarding completion')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->properties['first_name_severity'])->toBeIn(['minor', 'none']);
    expect($activity->properties['last_name_severity'])->toBeIn(['minor', 'none']);
});

test('allows medium name change with warning (50-80% similar)', function () {
    Illuminate\Support\Facades\Mail::fake();

    /** @var User $user */
    $user = User::factory()->create(['email' => 'test@secpal.dev']);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Hans',
        'last_name' => 'Müller',
        'date_of_birth' => '1980-08-08',
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Medium change: Adding additional name
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@secpal.dev',
        'password' => 'SecurePassword123!',
        'first_name' => 'Hans-Peter', // Added hyphenated name
        'last_name' => 'Müller-Schmidt', // Added double name
        'date_of_birth' => '1980-08-08',
    ]);

    $response->assertOk();

    // Verify name was updated
    $employee->refresh();
    expect($employee->first_name)->toBe('Hans-Peter');
    expect($employee->last_name)->toBe('Müller-Schmidt');

    // Verify HR notification was sent
    Illuminate\Support\Facades\Mail::assertQueued(App\Mail\OnboardingNameChangedMail::class, function ($mail) use ($employee) {
        return $mail->employee->id === $employee->id
            && $mail->oldFirstName === 'Hans'
            && $mail->oldLastName === 'Müller'
            && ($mail->firstNameValidation['severity'] === 'medium' || $mail->lastNameValidation['severity'] === 'medium');
    });
});

test('blocks major name change (<50% similar) with generic identity-verification error', function () {
    /** @var User $user */
    $user = User::factory()->create(['email' => 'test@secpal.dev']);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Hans',
        'last_name' => 'Müller',
        'date_of_birth' => '1975-11-30',
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Major change: Completely different name
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@secpal.dev',
        'password' => 'SecurePassword123!',
        'first_name' => 'Maria', // Completely different
        'last_name' => 'Schmidt', // Completely different
        'date_of_birth' => '1975-11-30',
    ]);

    // Identity verification failures share ONE generic message so they cannot be
    // used as a field oracle (and count toward the throttle bucket).
    $response->assertStatus(422)
        ->assertJsonStructure(['message'])
        ->assertJsonMissing(['errors']);

    expect($response->json('message'))
        ->toBe('We could not verify your identity with the details provided. For security reasons this onboarding link has been deactivated. Please contact HR for a new invitation.');

    // Verify name was NOT updated
    $employee->refresh();
    expect($employee->first_name)->toBe('Hans');
    expect($employee->last_name)->toBe('Müller');
});

test('allows unchanged name without HR notification', function () {
    Illuminate\Support\Facades\Mail::fake();

    /** @var User $user */
    $user = User::factory()->create(['email' => 'test@secpal.dev']);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Complete with same names
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@secpal.dev',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertOk();

    // Verify NO HR notification was sent
    Illuminate\Support\Facades\Mail::assertNothingQueued();
    Illuminate\Support\Facades\Mail::assertNotSent(App\Mail\OnboardingNameChangedMail::class);
});

// Bug fix tests: User name sync and auto-login

test('synchronizes user name with employee name after onboarding', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'name' => 'Max Mustermann', // Old name
        'email' => 'max@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'date_of_birth' => '1990-01-01',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Complete onboarding with name change Max → Maximilian
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'Maximilian', // Name changed
        'last_name' => 'Mustermann',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertOk();

    // Assert: Employee name was updated
    $employee->refresh();
    expect($employee->first_name)->toBe('Maximilian')
        ->and($employee->last_name)->toBe('Mustermann');

    // Assert: User name was synchronized with employee name (BUG FIX)
    $user->refresh();
    expect($user->name)->toBe('Maximilian Mustermann')
        ->and($user->email)->toBe('max@secpal.dev');

    // Assert: Response includes updated user name
    $response->assertJson([
        'data' => [
            'user' => [
                'name' => 'Maximilian Mustermann',
            ],
        ],
    ]);
});

test('creates activity log for automatic login after onboarding', function () {
    /** @var User $user */
    $user = User::factory()->create();

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Complete onboarding
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertOk();

    // Assert: Activity log was created for automatic login (BUG FIX)
    expect(Spatie\Activitylog\Models\Activity::where('log_name', 'authentication')
        ->where('causer_id', $user->id)
        ->where('description', 'User logged in after onboarding completion')
        ->exists())->toBeTrue();

    // Verify properties contain method='onboarding_completion'
    $activityLog = Spatie\Activitylog\Models\Activity::where('log_name', 'authentication')
        ->where('causer_id', $user->id)
        ->where('description', 'User logged in after onboarding completion')
        ->first();

    expect($activityLog)->not->toBeNull();
    $properties = $activityLog->properties;
    expect($properties->get('method'))->toBe('onboarding_completion');
    expect($properties->get('ip'))->not->toBeNull();
    expect($properties->get('user_agent'))->not->toBeNull();
});

test('automatically logs user in with session after onboarding (no token)', function () {
    /** @var User $user */
    $user = User::factory()->create();

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Complete onboarding
    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
    ]);

    $response->assertOk();

    // Assert: Response does NOT include token (uses session cookie instead) (BUG FIX)
    $response->assertJsonMissing(['token'])
        ->assertJsonStructure([
            'message',
            'data' => [
                'user' => ['id', 'email', 'name'],
                'employee' => ['id', 'first_name', 'last_name', 'status'],
            ],
        ]);

    // Assert: User is authenticated via session (web guard)
    expect(auth()->guard('web')->check())->toBeTrue()
        ->and(auth()->guard('web')->id())->toBe($user->id);

    // Assert: Session was regenerated (security measure)
    expect(session()->getId())->not->toBeNull();
});

// New test: only first name changes (medium severity)
test('sends HR notification when only first name changes with medium severity', function () {
    $user = User::factory()->create(['name' => '', 'email' => 'max@secpal.dev']);
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'date_of_birth' => '1990-05-20',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    Illuminate\Support\Facades\Mail::fake();

    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'Maximilian', // Medium change (prefix pattern ~72% similar)
        'last_name' => 'Mustermann',  // Unchanged
        'date_of_birth' => '1990-05-20',
    ]);

    $response->assertOk();

    // Assert: HR notification was sent for medium severity first name change
    Illuminate\Support\Facades\Mail::assertQueued(App\Mail\OnboardingNameChangedMail::class, function ($mail) use ($employee) {
        return $mail->hasTo(config('mail.hr_email', config('mail.from.address')))
            && $mail->employee->id === $employee->id
            && $mail->oldFirstName === 'Max'
            && $mail->employee->first_name === 'Maximilian'
            && $mail->oldLastName === 'Mustermann'
            && $mail->employee->last_name === 'Mustermann';
    });
});

// New test: only last name changes (medium severity)
test('sends HR notification when only last name changes with medium severity', function () {
    $user = User::factory()->create(['name' => '', 'email' => 'hans@secpal.dev']);
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Hans',
        'last_name' => 'Müller',
        'date_of_birth' => '1978-09-09',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    Illuminate\Support\Facades\Mail::fake();

    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'Hans',                // Unchanged
        'last_name' => 'Müller-Schmidtmann',  // Medium change (prefix pattern ~73% similar)
        'date_of_birth' => '1978-09-09',
    ]);

    $response->assertOk();

    // Assert: HR notification was sent for medium severity last name change
    Illuminate\Support\Facades\Mail::assertQueued(App\Mail\OnboardingNameChangedMail::class, function ($mail) use ($employee) {
        return $mail->hasTo(config('mail.hr_email', config('mail.from.address')))
            && $mail->employee->id === $employee->id
            && $mail->oldFirstName === 'Hans'
            && $mail->employee->first_name === 'Hans'
            && $mail->oldLastName === 'Müller'
            && $mail->employee->last_name === 'Müller-Schmidtmann';
    });
});

// New test: mixed severity (first name minor, last name medium)
test('sends HR notification when mixed severity changes occur', function () {
    $user = User::factory()->create(['name' => '', 'email' => 'hanz@secpal.dev']);
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Hanz',
        'last_name' => 'Schmidt',
        'date_of_birth' => '1982-12-12',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    Illuminate\Support\Facades\Mail::fake();

    $response = $this->withSession([])->postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'Hans',       // Minor change (typo correction, >80% similar)
        'last_name' => 'Schmidt-Weber', // Medium change (hyphenated addition)
        'date_of_birth' => '1982-12-12',
    ]);

    $response->assertOk();

    // Assert: HR notification was sent because of medium severity last name change
    // (even though first name is only minor)
    Illuminate\Support\Facades\Mail::assertQueued(App\Mail\OnboardingNameChangedMail::class, function ($mail) use ($employee) {
        return $mail->hasTo(config('mail.hr_email', config('mail.from.address')))
            && $mail->employee->id === $employee->id
            && $mail->oldFirstName === 'Hanz'
            && $mail->employee->first_name === 'Hans'
            && $mail->oldLastName === 'Schmidt'
            && $mail->employee->last_name === 'Schmidt-Weber';
    });
});
