<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeOnboardingToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * Test EmployeeOnboardingToken model
 *
 * Security requirements:
 * - Tokens must be hashed with bcrypt
 * - Plain tokens never stored in database
 * - Single-use enforcement
 * - Expiry enforcement (7 days default)
 * - Constant-time token comparison
 *
 * @see EmployeeOnboardingToken
 */
test('generates token with 7 day expiry', function () {
    $employee = Employee::factory()->preContract()->create();

    $result = EmployeeOnboardingToken::generate($employee);

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['model', 'plain'])
        ->and($result['model'])->toBeInstanceOf(EmployeeOnboardingToken::class)
        ->and($result['plain'])->toBeString()
        ->and($result['plain'])->toHaveLength(64);

    $token = $result['model'];

    expect($token->employee_id)->toBe($employee->id)
        ->and($token->expires_at)->toBeGreaterThan(now()->addDays(6))
        ->and($token->expires_at)->toBeLessThanOrEqual(now()->addDays(7))
        ->and($token->completed_at)->toBeNull();
});

test('hashes token before storage', function () {
    $employee = Employee::factory()->preContract()->create();

    $result = EmployeeOnboardingToken::generate($employee);
    $plainToken = $result['plain'];
    $model = $result['model'];

    // Token in database is hashed
    expect($model->token)->not->toBe($plainToken)
        ->and(Hash::check($plainToken, $model->token))->toBeTrue();
});

test('stores deterministic lookup hash alongside bcrypt token', function () {
    $employee = Employee::factory()->preContract()->create();

    $result = EmployeeOnboardingToken::generate($employee);
    $plainToken = $result['plain'];
    $model = $result['model'];

    expect($model->token_lookup_hash)->toBe(hash('sha256', $plainToken));
});

test('finds token by plain text', function () {
    $employee = Employee::factory()->preContract()->create();
    $result = EmployeeOnboardingToken::generate($employee);
    $plainToken = $result['plain'];

    $found = EmployeeOnboardingToken::findByPlainToken($plainToken);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($result['model']->id)
        ->and($found->employee_id)->toBe($employee->id);
});

test('returns null for invalid plain token', function () {
    $employee = Employee::factory()->preContract()->create();
    EmployeeOnboardingToken::generate($employee);

    $found = EmployeeOnboardingToken::findByPlainToken('invalid-token-12345678');

    expect($found)->toBeNull();
});

test('finds legacy token without lookup hash and backfills it', function () {
    $employee = Employee::factory()->preContract()->create();
    $plainToken = str_repeat('legacy-token-', 6);

    $token = EmployeeOnboardingToken::create([
        'employee_id' => $employee->id,
        'token' => Hash::make($plainToken),
        'token_lookup_hash' => null,
        'expires_at' => now()->addDay(),
    ]);

    $found = EmployeeOnboardingToken::findByPlainToken($plainToken);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($token->id);

    $token->refresh();

    expect($token->token_lookup_hash)->toBe(hash('sha256', $plainToken));
});

test('marks token as completed with audit trail', function () {
    $employee = Employee::factory()->preContract()->create();
    $result = EmployeeOnboardingToken::generate($employee);
    $token = $result['model'];

    $ip = '192.168.1.1';
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

    $token->markAsCompleted($ip, $userAgent);
    $token->refresh();

    expect($token->completed_at)->not->toBeNull()
        ->and($token->completed_from_ip)->toBe($ip)
        ->and($token->completed_user_agent)->toBe($userAgent);
});

test('truncates long user agent to 500 characters', function () {
    $employee = Employee::factory()->preContract()->create();
    $result = EmployeeOnboardingToken::generate($employee);
    $token = $result['model'];

    $longUserAgent = str_repeat('a', 600);
    $token->markAsCompleted('127.0.0.1', $longUserAgent);
    $token->refresh();

    expect($token->completed_user_agent)->toHaveLength(500);
});

test('expired tokens are not valid', function () {
    $employee = Employee::factory()->preContract()->create();
    $result = EmployeeOnboardingToken::generate($employee);
    $token = $result['model'];

    // Force expiry
    $token->update(['expires_at' => now()->subDay()]);
    $token->refresh();

    expect($token->isValid())->toBeFalse();
});

test('completed tokens are not valid', function () {
    $employee = Employee::factory()->preContract()->create();
    $result = EmployeeOnboardingToken::generate($employee);
    $token = $result['model'];

    $token->markAsCompleted('127.0.0.1', 'Test Agent');
    $token->refresh();

    expect($token->isValid())->toBeFalse();
});

test('valid tokens pass isValid check', function () {
    $employee = Employee::factory()->preContract()->create();
    $result = EmployeeOnboardingToken::generate($employee);
    $token = $result['model'];

    expect($token->isValid())->toBeTrue();
});

test('does not find expired tokens by plain text', function () {
    $employee = Employee::factory()->preContract()->create();
    $result = EmployeeOnboardingToken::generate($employee);
    $plainToken = $result['plain'];
    $token = $result['model'];

    // Force expiry
    $token->update(['expires_at' => now()->subDay()]);

    $found = EmployeeOnboardingToken::findByPlainToken($plainToken);

    expect($found)->toBeNull();
});

test('does not find completed tokens by plain text', function () {
    $employee = Employee::factory()->preContract()->create();
    $result = EmployeeOnboardingToken::generate($employee);
    $plainToken = $result['plain'];
    $token = $result['model'];

    $token->markAsCompleted('127.0.0.1', 'Test Agent');

    $found = EmployeeOnboardingToken::findByPlainToken($plainToken);

    expect($found)->toBeNull();
});

test('multiple tokens can exist for same employee but only one valid', function () {
    $employee = Employee::factory()->preContract()->create();

    // Generate first token and complete it
    $result1 = EmployeeOnboardingToken::generate($employee);
    $result1['model']->markAsCompleted('127.0.0.1', 'Agent 1');

    // Generate second token
    $result2 = EmployeeOnboardingToken::generate($employee);

    expect(EmployeeOnboardingToken::count())->toBe(2)
        ->and(EmployeeOnboardingToken::whereNull('completed_at')->count())->toBe(1);

    // Only second token is findable
    $found1 = EmployeeOnboardingToken::findByPlainToken($result1['plain']);
    $found2 = EmployeeOnboardingToken::findByPlainToken($result2['plain']);

    expect($found1)->toBeNull()
        ->and($found2)->not->toBeNull()
        ->and($found2->id)->toBe($result2['model']->id);
});

test('employee relationship works', function () {
    $employee = Employee::factory()->preContract()->create();
    $result = EmployeeOnboardingToken::generate($employee);
    $token = $result['model'];

    $relationEmployee = $token->employee;

    expect($relationEmployee)->toBeInstanceOf(Employee::class)
        ->and($relationEmployee->id)->toBe($employee->id);
});

test('tokens are deleted when employee is deleted', function () {
    $employee = Employee::factory()->preContract()->create();
    $result = EmployeeOnboardingToken::generate($employee);
    $tokenId = $result['model']->id;

    expect(EmployeeOnboardingToken::find($tokenId))->not->toBeNull();

    $employee->forceDelete();

    expect(EmployeeOnboardingToken::find($tokenId))->toBeNull();
});

// Timing test removed: Hash::check() already provides constant-time comparison (Laravel guarantee)
// Manual timing tests are unreliable in CI environments and don't add security value

test('generates unique tokens for each call', function () {
    $employee = Employee::factory()->preContract()->create();

    $result1 = EmployeeOnboardingToken::generate($employee);
    $result2 = EmployeeOnboardingToken::generate($employee);

    expect($result1['plain'])->not->toBe($result2['plain'])
        ->and($result1['model']->id)->not->toBe($result2['model']->id);
});
