<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeOnboardingToken;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function () {
    TenantKey::setKekPath(null);
});

test('completes onboarding with valid token', function () {
    // Arrange: Create pre-contract employee with user
    /** @var User $user */
    $user = User::factory()->create([
        'password' => Hash::make('temporary-password'), // Will be replaced during onboarding
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John', // Use realistic names to avoid validation issues
        'last_name' => 'Doe',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Act: Complete onboarding with same names (no change)
    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => $user->email,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    // Assert: Response is successful
    $response->assertOk()
        ->assertJsonStructure([
            'message',
            'data' => [
                'token',
                'user' => ['id', 'email', 'name'],
                'employee' => ['id', 'first_name', 'last_name', 'status'],
            ],
        ]);

    // Assert: Employee name updated
    $employee->refresh();
    expect($employee->first_name)->toBe('John')
        ->and($employee->last_name)->toBe('Doe')
        ->and($employee->onboarding_started_at)->not->toBeNull();

    // Assert: Password set on user
    $user = $employee->user;
    expect($user)->not->toBeNull();
    expect(Hash::check('SecurePassword123!', $user->password))->toBeTrue();

    // Assert: Token marked as completed
    $tokenModel = $tokenData['model'];
    $tokenModel->refresh();
    expect($tokenModel->completed_at)->not->toBeNull()
        ->and($tokenModel->completed_from_ip)->not->toBeNull()
        ->and($tokenModel->completed_user_agent)->not->toBeNull();
});

test('rejects invalid token', function () {
    $response = postJson('/v1/onboarding/complete', [
        'token' => 'invalid-token-that-does-not-exist',
        'email' => 'test@example.com',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('rejects expired token', function () {
    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'test@example.com',
    ]);
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Expire token
    $tokenData['model']->update(['expires_at' => now()->subDay()]);

    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@example.com',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

// TODO: Uncomment when Issue #419 is resolved (User account creation)
// See: https://github.com/SecPal/frontend/issues/419
/*
test('rejects already used token', function () {
    // TODO: Blocked by Issue #419 - Employee factory doesn't create User accounts
    // This test requires a User account to be associated with the Employee
    // Will be enabled once frontend User registration is implemented
    $this->markTestSkipped('Requires User account functionality (Issue #419)');

    $employee = Employee::factory()->preContract()->create();
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Complete onboarding once
    postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ])->assertOk();

    // Try to use same token again
    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'password' => 'DifferentPassword456!',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});
*/

test('rejects onboarding for non-pre-contract employee', function () {
    // Create employee with status other than PRE_CONTRACT
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->create([
        'status' => Employee::STATUS_ACTIVE,
        'email' => 'test@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@example.com',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Onboarding is only available for pre-contract employees.',
        ]);
});

test('validates required fields', function () {
    $response = postJson('/v1/onboarding/complete', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['token', 'email', 'password', 'first_name', 'last_name']);
});

test('validates password strength', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'test@example.com',
        'user_id' => $user->id,
    ]);
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@example.com',
        'password' => 'weak', // Too weak password
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('rate limits onboarding attempts', function () {
    // Make 4 requests (limit is 3 per 10 minutes)
    for ($i = 0; $i < 4; $i++) {
        $response = postJson('/v1/onboarding/complete', [
            'token' => 'invalid-token',
            'email' => 'test@example.com',
            'password' => 'SecurePassword123!',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    }

    // 4th request should be rate limited
    $response->assertStatus(429)
        ->assertJson([
            'message' => 'Too many onboarding attempts. Please try again later.',
        ]);
});

// ===== SECURITY TESTS: Email Validation =====

test('SECURITY: rejects valid token with wrong email', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'correct@example.com',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'correct@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Attacker tries to use valid token with different email
    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'attacker@example.com', // Wrong email!
        'password' => 'SecurePassword123!',
        'first_name' => 'Hacker',
        'last_name' => 'McHackface',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid onboarding link. Email does not match.',
        ]);

    // Verify employee was NOT modified
    $employee->refresh();
    expect($employee->first_name)->not->toBe('Hacker');
});

test('SECURITY: validates email case-sensitively', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'test@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Try with uppercase email
    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'TEST@EXAMPLE.COM', // Wrong case
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid onboarding link. Email does not match.',
        ]);
});

test('SECURITY: prevents token hijacking scenario', function () {
    // Scenario: Attacker intercepts token for victim@example.com
    // Tries to use it to create account for attacker@example.com

    /** @var User $victimUser */
    $victimUser = User::factory()->create([
        'email' => 'victim@example.com',
    ]);

    /** @var Employee $victimEmployee */
    $victimEmployee = Employee::factory()->preContract()->create([
        'first_name' => 'Victim',
        'last_name' => 'User',
        'email' => 'victim@example.com',
        'user_id' => $victimUser->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($victimEmployee);
    $interceptedToken = $tokenData['plain'];

    // Attacker tries to complete onboarding with their own email
    $response = postJson('/v1/onboarding/complete', [
        'token' => $interceptedToken,
        'email' => 'attacker@example.com',
        'password' => 'AttackerPassword123!',
        'first_name' => 'Attacker',
        'last_name' => 'McEvil',
    ]);

    // Should be rejected
    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid onboarding link. Email does not match.',
        ]);

    // Verify victim's data was NOT compromised
    $victimEmployee->refresh();
    expect($victimEmployee->first_name)->toBe('Victim');
    expect($victimEmployee->last_name)->toBe('User');
    expect($victimEmployee->email)->toBe('victim@example.com');

    // Verify victim's password was NOT changed
    $victimUser->refresh();
    expect(Hash::check('AttackerPassword123!', $victimUser->password))->toBeFalse();
});

test('logs name changes with enhanced activity log', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'OldFirst',
        'last_name' => 'OldLast',
        'email' => 'test@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Complete onboarding with different names
    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@example.com',
        'password' => 'SecurePassword123!',
        'first_name' => 'NewFirst',
        'last_name' => 'NewLast',
    ]);

    $response->assertOk();

    // Verify activity log was created
    $activity = \Spatie\Activitylog\Models\Activity::where('subject_id', $employee->id)
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
        'email' => 'test@example.com',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Complete onboarding with same names
    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@example.com',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertOk();

    // Verify NO activity log for name change was created
    $activity = \Spatie\Activitylog\Models\Activity::where('subject_id', $employee->id)
        ->where('log_name', 'employee-onboarding')
        ->where('description', 'Employee name changed during onboarding completion')
        ->first();

    expect($activity)->toBeNull();
});

// TODO: Uncomment when Issue #419 is resolved (User account creation)
// See: https://github.com/SecPal/frontend/issues/419
/*
test('creates sanctum token after successful completion', function () {
    // TODO: Blocked by Issue #419 - Employee factory doesn't create User accounts
    // This test requires a User account to be associated with the Employee
    // Will be enabled once frontend User registration is implemented
    $this->markTestSkipped('Requires User account functionality (Issue #419)');

    $employee = Employee::factory()->preContract()->create();
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertOk();

    // Extract token from response
    $data = $response->json('data');
    expect($data)->toHaveKey('token');
    $sanctumToken = $data['token'];

    // Use token to authenticate
    $authResponse = $this->withToken($sanctumToken)->getJson('/v1/me');
    $authResponse->assertOk();
});
*/

// ============================================================================
// Name Change Validation Tests (Hybrid Approach: Similarity + HR Notification)
// ============================================================================

test('allows minor name correction (typo, >80% similar)', function () {
    /** @var User $user */
    $user = User::factory()->create(['email' => 'test@example.com']);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Hanns', // Typo
        'last_name' => 'Mueller', // Alternate spelling
        'email' => 'test@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Minor corrections should be allowed
    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@example.com',
        'password' => 'SecurePassword123!',
        'first_name' => 'Hans', // Corrected
        'last_name' => 'Müller', // Corrected with umlaut
    ]);

    $response->assertOk();

    // Verify name was updated
    $employee->refresh();
    expect($employee->first_name)->toBe('Hans');
    expect($employee->last_name)->toBe('Müller');

    // Verify activity log contains severity info
    $activity = \Spatie\Activitylog\Models\Activity::where('subject_id', $employee->id)
        ->where('log_name', 'employee-onboarding')
        ->where('description', 'Employee name changed during onboarding completion')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->properties['first_name_severity'])->toBeIn(['minor', 'none']);
    expect($activity->properties['last_name_severity'])->toBeIn(['minor', 'none']);
});

test('allows medium name change with warning (50-80% similar)', function () {
    \Illuminate\Support\Facades\Mail::fake();

    /** @var User $user */
    $user = User::factory()->create(['email' => 'test@example.com']);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Hans',
        'last_name' => 'Müller',
        'email' => 'test@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Medium change: Adding additional name
    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@example.com',
        'password' => 'SecurePassword123!',
        'first_name' => 'Hans-Peter', // Added hyphenated name
        'last_name' => 'Müller-Schmidt', // Added double name
    ]);

    $response->assertOk();

    // Verify name was updated
    $employee->refresh();
    expect($employee->first_name)->toBe('Hans-Peter');
    expect($employee->last_name)->toBe('Müller-Schmidt');

    // Verify HR notification was sent
    \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\OnboardingNameChangedMail::class, function ($mail) use ($employee) {
        return $mail->employee->id === $employee->id
            && $mail->oldFirstName === 'Hans'
            && $mail->oldLastName === 'Müller'
            && ($mail->firstNameValidation['severity'] === 'medium' || $mail->lastNameValidation['severity'] === 'medium');
    });
});

test('blocks major name change (<50% similar)', function () {
    /** @var User $user */
    $user = User::factory()->create(['email' => 'test@example.com']);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Hans',
        'last_name' => 'Müller',
        'email' => 'test@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Major change: Completely different name
    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@example.com',
        'password' => 'SecurePassword123!',
        'first_name' => 'Maria', // Completely different
        'last_name' => 'Schmidt', // Completely different
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure([
            'message',
            'errors' => ['first_name'],
        ]);

    // Verify name was NOT updated
    $employee->refresh();
    expect($employee->first_name)->toBe('Hans');
    expect($employee->last_name)->toBe('Müller');
});

test('allows unchanged name without HR notification', function () {
    \Illuminate\Support\Facades\Mail::fake();

    /** @var User $user */
    $user = User::factory()->create(['email' => 'test@example.com']);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Complete with same names
    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'email' => 'test@example.com',
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertOk();

    // Verify NO HR notification was sent
    \Illuminate\Support\Facades\Mail::assertNothingQueued();
    \Illuminate\Support\Facades\Mail::assertNotSent(\App\Mail\OnboardingNameChangedMail::class);
});
