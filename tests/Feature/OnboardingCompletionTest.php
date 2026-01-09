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

    // Helper to create pre-contract employee with user
    $this->createPreContractEmployee = function (array $employeeAttrs = [], array $userAttrs = []): Employee {
        /** @var User $user */
        $user = User::factory()->create(array_merge([
            'password' => Hash::make('temporary-password'),
        ], $userAttrs));

        /** @var Employee $employee */
        $employee = Employee::factory()->preContract()->create(array_merge([
            'email' => $user->email,
            'user_id' => $user->id,
        ], $employeeAttrs));

        return $employee;
    };
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
        'first_name' => 'Temporary',
        'last_name' => 'Name',
        'email' => $user->email,
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Act: Complete onboarding
    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
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
    $employee = Employee::factory()->preContract()->create();
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Expire token
    $tokenData['model']->update(['expires_at' => now()->subDay()]);

    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
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
    markTestSkipped('Requires User account functionality (Issue #419)');

    employee = Employee::factory()->preContract()->create();
    tokenData = EmployeeOnboardingToken::generate(employee);
    plainToken = tokenData['plain'];

    // Complete onboarding once
    postJson('/v1/onboarding/complete', [
        'token' => plainToken,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ])->assertOk();

    // Try to use same token again
    response = postJson('/v1/onboarding/complete', [
        'token' => plainToken,
        'password' => 'DifferentPassword456!',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
    ]);

    response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});
*/

test('rejects onboarding for non-pre-contract employee', function () {
    // Create employee with status other than PRE_CONTRACT
    /** @var Employee $employee */
    $employee = Employee::factory()->create([
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
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
        ->assertJsonValidationErrors(['token', 'password', 'first_name', 'last_name']);
});

test('validates password strength', function () {
    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create();
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = postJson('/v1/onboarding/complete', [
        'token' => $plainToken,
        'password' => 'weak', // Too weak password
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('rate limits onboarding attempts', function () {
    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create();

    // Make 4 requests (limit is 3 per 10 minutes)
    for ($i = 0; $i < 4; $i++) {
        $response = postJson('/v1/onboarding/complete', [
            'token' => 'invalid-token',
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

// TODO: Uncomment when Issue #419 is resolved (User account creation)
// See: https://github.com/SecPal/frontend/issues/419
/*
test('creates sanctum token after successful completion', function () {
    // TODO: Blocked by Issue #419 - Employee factory doesn't create User accounts
    // This test requires a User account to be associated with the Employee
    // Will be enabled once frontend User registration is implemented
    markTestSkipped('Requires User account functionality (Issue #419)');

    employee = Employee::factory()->preContract()->create();
    tokenData = EmployeeOnboardingToken::generate(employee);
    plainToken = tokenData['plain'];

    response = postJson('/v1/onboarding/complete', [
        'token' => plainToken,
        'password' => 'SecurePassword123!',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    response->assertOk();

    // Extract token from response
    data = response->json('data');
    expect(data)->toHaveKey('token');
    sanctumToken = data['token'];

    // Use token to authenticate
    authResponse = this->withToken(sanctumToken)->getJson('/v1/me');
    authResponse->assertOk();
});
*/
