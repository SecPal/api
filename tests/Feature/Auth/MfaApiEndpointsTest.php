<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laragear\TwoFactor\Models\TwoFactorAuthentication;

uses(RefreshDatabase::class);

afterEach(function () {
    TwoFactorAuthentication::generateRecoveryCodesUsing();
    Cache::flush();
});

test('session login returns an MFA challenge and only authenticates after verification', function () {
    $user = User::factory()->create([
        'email' => 'mfa-session@secpal.dev',
        'password' => bcrypt('password123'),
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $recoveryCode = $user->getRecoveryCodes()->firstWhere('used_at', null)['code'];

    $loginResponse = $this->withHeaders(spaCsrfHeaders($this))->postJson('/v1/auth/login', [
        'email' => 'mfa-session@secpal.dev',
        'password' => 'password123',
    ]);

    $loginResponse->assertStatus(202)
        ->assertJson([
            'challenge' => [
                'purpose' => 'login',
                'login_context' => 'session',
                'primary_method' => 'totp',
            ],
        ]);

    $this->withHeaders(spaHeaders())
        ->getJson('/v1/me')
        ->assertUnauthorized();

    $verificationResponse = $this->withHeaders(spaCsrfHeaders($this))
        ->postJson('/v1/auth/mfa-challenges/'.$loginResponse->json('challenge.id').'/verify', [
            'method' => 'recovery_code',
            'code' => $recoveryCode,
        ]);

    $verificationResponse->assertOk()
        ->assertJson([
            'authentication' => [
                'mode' => 'session',
                'mfa_completed' => true,
            ],
            'user' => [
                'email' => 'mfa-session@secpal.dev',
            ],
        ]);

    $this->withHeaders(spaHeaders())
        ->getJson('/v1/me')
        ->assertOk()
        ->assertJson([
            'email' => 'mfa-session@secpal.dev',
        ]);
});

test('token login returns an MFA challenge and issues a token only after recovery-code verification', function () {
    $user = User::factory()->create([
        'email' => 'mfa-token@secpal.dev',
        'password' => bcrypt('password123'),
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $recoveryCode = $user->getRecoveryCodes()->firstWhere('used_at', null)['code'];

    $loginResponse = $this->postJson('/v1/auth/token', [
        'email' => 'mfa-token@secpal.dev',
        'password' => 'password123',
        'device_name' => 'android-phone',
    ]);

    $loginResponse->assertStatus(202)
        ->assertJson([
            'challenge' => [
                'login_context' => 'token',
            ],
        ]);

    expect($user->fresh()->tokens()->count())->toBe(0);

    $verificationResponse = $this->postJson('/v1/auth/mfa-challenges/'.$loginResponse->json('challenge.id').'/verify', [
        'method' => 'recovery_code',
        'code' => $recoveryCode,
    ]);

    $verificationResponse->assertOk()
        ->assertJson([
            'authentication' => [
                'mode' => 'token',
                'mfa_completed' => true,
            ],
            'user' => [
                'email' => 'mfa-token@secpal.dev',
            ],
        ]);

    expect($verificationResponse->json('token'))->not->toBeEmpty()
        ->and($user->fresh()->tokens()->count())->toBe(1)
        ->and($user->fresh()->getRemainingTwoFactorRecoveryCodesCount())->toBe(9);
});

test('authenticated user can start and confirm a TOTP enrollment', function () {
    $user = User::factory()->create([
        'email' => 'mfa-enroll@secpal.dev',
    ]);

    $this->actingAs($user, 'sanctum');

    $prepareResponse = $this->postJson('/v1/me/mfa/totp/enrollment');

    $prepareResponse->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'issuer',
                'account_name',
                'manual_entry_key',
                'otpauth_uri',
                'expires_at',
            ],
        ]);

    $confirmResponse = $this->postJson('/v1/me/mfa/totp/enrollment/confirm', [
        'code' => $user->fresh()->makeTwoFactorCode(),
    ]);

    $confirmResponse->assertOk()
        ->assertJson([
            'data' => [
                'status' => [
                    'enabled' => true,
                    'method' => 'totp',
                    'recovery_codes_remaining' => 10,
                ],
            ],
        ]);

    expect($confirmResponse->json('data.recovery_codes.codes'))->toHaveCount(10);
});

test('authenticated user can read MFA status, regenerate recovery codes, and disable MFA', function () {
    $user = User::factory()->create([
        'email' => 'mfa-status@secpal.dev',
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $this->actingAs($user, 'sanctum');

    $this->getJson('/v1/me/mfa')
        ->assertOk()
        ->assertJson([
            'data' => [
                'enabled' => true,
                'method' => 'totp',
                'recovery_codes_remaining' => 10,
            ],
        ]);

    $recoveryCode = $user->fresh()->getRecoveryCodes()->firstWhere('used_at', null)['code'];

    $regenerateResponse = $this->postJson('/v1/me/mfa/recovery-codes/regenerate', [
        'method' => 'recovery_code',
        'code' => $recoveryCode,
    ]);

    $regenerateResponse->assertOk();
    expect($regenerateResponse->json('data.recovery_codes.codes'))->toHaveCount(10);

    $replacementRecoveryCode = $regenerateResponse->json('data.recovery_codes.codes.0');

    $disableResponse = $this->deleteJson('/v1/me/mfa', [
        'method' => 'recovery_code',
        'code' => $replacementRecoveryCode,
    ]);

    $disableResponse->assertOk()
        ->assertJson([
            'data' => [
                'enabled' => false,
                'method' => null,
                'recovery_codes_remaining' => 0,
            ],
        ]);
});

test('confirming an expired pending enrollment returns conflict', function () {
    $user = User::factory()->create([
        'email' => 'mfa-expired@secpal.dev',
    ]);

    $this->actingAs($user, 'sanctum');

    $this->postJson('/v1/me/mfa/totp/enrollment')->assertCreated();

    $user->fresh()->twoFactorAuth->forceFill([
        'created_at' => now()->subHours(4),
    ])->save();

    $this->actingAs($user->fresh(), 'sanctum');

    $this->postJson('/v1/me/mfa/totp/enrollment/confirm', [
        'code' => $user->fresh()->makeTwoFactorCode(),
    ])->assertStatus(409)
        ->assertJson([
            'message' => 'The pending two-factor enrollment has expired. Please start a new enrollment.',
        ]);
});

test('starting a new enrollment while MFA is already enabled returns conflict', function () {
    $user = User::factory()->create([
        'email' => 'mfa-already-enabled@secpal.dev',
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $this->actingAs($user, 'sanctum')
        ->postJson('/v1/me/mfa/totp/enrollment')
        ->assertStatus(409)
        ->assertJson([
            'message' => 'Two-factor authentication is already enabled for this account.',
        ]);
});
