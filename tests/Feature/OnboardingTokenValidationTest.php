<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeOnboardingToken;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

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

test('validates token with correct email and returns employee data', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'email' => 'test@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email='.urlencode('test@example.com'));

    $response->assertOk()
        ->assertJson([
            'data' => [
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
                'email' => 'test@example.com',
            ],
        ]);
});

test('SECURITY: rejects valid token with wrong email', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'correct@example.com',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'email' => 'correct@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Attacker tries to use valid token with different email
    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email='.urlencode('attacker@example.com'));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid onboarding link. Email does not match.',
        ]);
});

test('SECURITY: validates email case-sensitively', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'email' => 'test@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Try with different case
    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email='.urlencode('TEST@EXAMPLE.COM'));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid onboarding link. Email does not match.',
        ]);
});

test('requires email parameter', function () {
    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create();
    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('requires token parameter', function () {
    $response = getJson('/v1/onboarding/validate-token?email=test@example.com');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['token']);
});

test('rejects invalid token', function () {
    $response = getJson('/v1/onboarding/validate-token?token=invalid-token&email=test@example.com');

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('rejects expired token', function () {
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

    // Expire token
    $tokenData['model']->update(['expires_at' => now()->subDay()]);

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email=test@example.com');

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('rejects already completed token', function () {
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

    // Mark token as completed
    $tokenData['model']->markAsCompleted('127.0.0.1', 'test-agent');

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email=test@example.com');

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('rejects token for non-pre-contract employee', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@example.com',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->create([
        'email' => 'test@example.com',
        'user_id' => $user->id,
        'status' => Employee::STATUS_ACTIVE, // Not PRE_CONTRACT
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email=test@example.com');

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Onboarding is only available for pre-contract employees.',
        ]);
});

test('handles URL-encoded special characters in email', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test+tag@example.com',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test+tag@example.com',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email='.urlencode('test+tag@example.com'));

    $response->assertOk()
        ->assertJson([
            'data' => [
                'email' => 'test+tag@example.com',
            ],
        ]);
});

test('rate limits validation attempts', function () {
    // Make 4 requests (limit is 3 per 10 minutes for onboarding throttle)
    for ($i = 0; $i < 4; $i++) {
        $response = getJson('/v1/onboarding/validate-token?token=invalid-token&email=test@example.com');
    }

    // 4th request should be rate limited
    $response->assertStatus(429)
        ->assertJson([
            'message' => 'Too many onboarding attempts. Please try again later.',
        ]);
});
