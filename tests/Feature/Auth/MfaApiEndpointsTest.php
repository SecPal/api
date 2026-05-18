<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Activity;
use App\Models\User;
use App\Services\LoginMfaChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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

test('invalid TOTP challenge attempts are rate limited with retry headers', function () {
    $user = User::factory()->create([
        'email' => 'mfa-rate-limit-totp@secpal.dev',
        'password' => bcrypt('password123'),
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $startChallenge = function () {
        $loginResponse = $this->postJson('/v1/auth/token', [
            'email' => 'mfa-rate-limit-totp@secpal.dev',
            'password' => 'password123',
            'device_name' => 'mfa-rate-limit-totp',
        ]);

        $loginResponse->assertStatus(202)
            ->assertJsonPath('challenge.id', fn (mixed $value): bool => is_string($value) && $value !== '');

        return (string) $loginResponse->json('challenge.id');
    };

    for ($i = 0; $i < 5; $i++) {
        $challengeId = $startChallenge();

        $this->postJson('/v1/auth/mfa-challenges/'.$challengeId.'/verify', [
            'method' => 'totp',
            'code' => '000000',
        ])->assertUnprocessable();
    }

    $response = $this->postJson('/v1/auth/mfa-challenges/'.(string) Str::uuid().'/verify', [
        'method' => 'totp',
        'code' => '000000',
    ]);

    $response->assertTooManyRequests()
        ->assertHeader('X-RateLimit-Limit', '5')
        ->assertHeader('X-RateLimit-Remaining', '0');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
});

test('invalid MFA verification forgets the login challenge', function () {
    $user = User::factory()->create([
        'email' => 'mfa-forget-challenge@secpal.dev',
        'password' => bcrypt('password123'),
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $loginResponse = $this->postJson('/v1/auth/token', [
        'email' => 'mfa-forget-challenge@secpal.dev',
        'password' => 'password123',
        'device_name' => 'mfa-forget-challenge',
    ]);

    $loginResponse->assertStatus(202)
        ->assertJsonPath('challenge.id', fn (mixed $value): bool => is_string($value) && $value !== '');

    $challengeId = (string) $loginResponse->json('challenge.id');

    $this->postJson('/v1/auth/mfa-challenges/'.$challengeId.'/verify', [
        'method' => 'totp',
        'code' => '000000',
    ])->assertUnprocessable();

    expect(app(LoginMfaChallengeService::class)->find($challengeId))->toBeNull();
});

test('invalid recovery-code challenge attempts are rate limited with retry headers', function () {
    $user = User::factory()->create([
        'email' => 'mfa-rate-limit-recovery@secpal.dev',
        'password' => bcrypt('password123'),
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $startChallenge = function () {
        $loginResponse = $this->postJson('/v1/auth/token', [
            'email' => 'mfa-rate-limit-recovery@secpal.dev',
            'password' => 'password123',
            'device_name' => 'mfa-rate-limit-recovery',
        ]);

        $loginResponse->assertStatus(202)
            ->assertJsonPath('challenge.id', fn (mixed $value): bool => is_string($value) && $value !== '');

        return (string) $loginResponse->json('challenge.id');
    };

    for ($i = 0; $i < 5; $i++) {
        $challengeId = $startChallenge();

        $this->postJson('/v1/auth/mfa-challenges/'.$challengeId.'/verify', [
            'method' => 'recovery_code',
            'code' => 'ZZZZZZZZ',
        ])->assertUnprocessable();
    }

    $response = $this->postJson('/v1/auth/mfa-challenges/'.(string) Str::uuid().'/verify', [
        'method' => 'recovery_code',
        'code' => 'ZZZZZZZZ',
    ]);

    $response->assertTooManyRequests()
        ->assertHeader('X-RateLimit-Limit', '5')
        ->assertHeader('X-RateLimit-Remaining', '0');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
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

    collect($confirmResponse->json('data.recovery_codes.codes'))->each(
        fn (mixed $code) => expect($code)
            ->toBeString()
            ->toMatch('/^[A-Z0-9]{8}$/')
    );

    $enableAudit = Activity::query()
        ->where('description', 'Enabled multi-factor authentication')
        ->latest('id')
        ->first();

    expect($enableAudit)->not->toBeNull()
        ->and($enableAudit?->properties['event'])->toBe('mfa_enabled');
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

    $regenerateAudit = Activity::query()
        ->where('description', 'Regenerated multi-factor recovery codes')
        ->latest('id')
        ->first();

    expect($regenerateAudit)->not->toBeNull()
        ->and($regenerateAudit?->properties['event'])->toBe('mfa_recovery_codes_regenerated')
        ->and($regenerateAudit?->properties['verification_method'])->toBe('recovery_code');

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

    $disableAudit = Activity::query()
        ->where('description', 'Disabled multi-factor authentication')
        ->latest('id')
        ->first();

    expect($disableAudit)->not->toBeNull()
        ->and($disableAudit?->properties['event'])->toBe('mfa_disabled')
        ->and($disableAudit?->properties['verification_method'])->toBe('recovery_code');
});

test('login challenge verification accepts grouped lowercase recovery codes while keeping canonical payload codes ungrouped', function () {
    $user = User::factory()->create([
        'email' => 'mfa-normalized-login@secpal.dev',
        'password' => bcrypt('password123'),
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $rawRecoveryCode = (string) $user->getRecoveryCodes()->firstWhere('used_at', null)['code'];
    $groupedLowercaseRecoveryCode = strtolower(substr($rawRecoveryCode, 0, 4).'-'.substr($rawRecoveryCode, 4));

    $loginResponse = $this->postJson('/v1/auth/token', [
        'email' => 'mfa-normalized-login@secpal.dev',
        'password' => 'password123',
        'device_name' => 'normalized-recovery-login',
    ]);

    $loginResponse->assertStatus(202);

    $this->postJson('/v1/auth/mfa-challenges/'.$loginResponse->json('challenge.id').'/verify', [
        'method' => 'recovery_code',
        'code' => $groupedLowercaseRecoveryCode,
    ])->assertOk()
        ->assertJson([
            'authentication' => [
                'mode' => 'token',
                'mfa_completed' => true,
            ],
        ]);

    expect($user->fresh()->getRemainingTwoFactorRecoveryCodesCount())->toBe(9);
});

test('login challenge verification rejects recovery codes with arbitrary punctuation', function () {
    $user = User::factory()->create([
        'email' => 'mfa-normalized-invalid@secpal.dev',
        'password' => bcrypt('password123'),
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $rawRecoveryCode = (string) $user->getRecoveryCodes()->firstWhere('used_at', null)['code'];
    $punctuatedRecoveryCode = substr($rawRecoveryCode, 0, 2).'!'.substr($rawRecoveryCode, 2, 2).'#'.substr($rawRecoveryCode, 4);

    $loginResponse = $this->postJson('/v1/auth/token', [
        'email' => 'mfa-normalized-invalid@secpal.dev',
        'password' => 'password123',
        'device_name' => 'normalized-recovery-invalid',
    ]);

    $loginResponse->assertStatus(202);

    $this->postJson('/v1/auth/mfa-challenges/'.$loginResponse->json('challenge.id').'/verify', [
        'method' => 'recovery_code',
        'code' => $punctuatedRecoveryCode,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    expect($user->fresh()->getRemainingTwoFactorRecoveryCodesCount())->toBe(10);
});

test('recovery code regeneration accepts grouped lowercase recovery codes', function () {
    $user = User::factory()->create([
        'email' => 'mfa-normalized-regenerate@secpal.dev',
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $this->actingAs($user, 'sanctum');

    $rawRecoveryCode = (string) $user->fresh()->getRecoveryCodes()->firstWhere('used_at', null)['code'];
    $groupedLowercaseRecoveryCode = strtolower(substr($rawRecoveryCode, 0, 4).'-'.substr($rawRecoveryCode, 4));

    $response = $this->postJson('/v1/me/mfa/recovery-codes/regenerate', [
        'method' => 'recovery_code',
        'code' => $groupedLowercaseRecoveryCode,
    ]);

    $response->assertOk();

    expect($response->json('data.recovery_codes.codes'))->toHaveCount(10);

    collect($response->json('data.recovery_codes.codes'))->each(
        fn (mixed $code) => expect($code)
            ->toBeString()
            ->toMatch('/^[A-Z0-9]{8}$/')
    );
});

test('consuming the final recovery code records an audit event when the backup set is depleted', function () {
    $user = User::factory()->create([
        'email' => 'mfa-depletion@secpal.dev',
        'password' => bcrypt('password123'),
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $recoveryCodes = $user->getRecoveryCodes()->pluck('code')->values();

    foreach ($recoveryCodes->slice(0, 9) as $recoveryCode) {
        expect($user->validateTwoFactorCode($recoveryCode))->toBeTrue();
    }

    $finalRecoveryCode = (string) $recoveryCodes->last();

    $loginResponse = $this->postJson('/v1/auth/token', [
        'email' => 'mfa-depletion@secpal.dev',
        'password' => 'password123',
        'device_name' => 'audit-check',
    ]);

    $loginResponse->assertStatus(202);

    $this->postJson('/v1/auth/mfa-challenges/'.$loginResponse->json('challenge.id').'/verify', [
        'method' => 'recovery_code',
        'code' => $finalRecoveryCode,
    ])->assertOk();

    $depletionAudit = Activity::query()
        ->where('description', 'Multi-factor recovery codes depleted')
        ->latest('id')
        ->first();

    expect($depletionAudit)->not->toBeNull()
        ->and($depletionAudit?->properties['event'])->toBe('mfa_recovery_codes_depleted')
        ->and($depletionAudit?->properties['recovery_codes_remaining'])->toBe(0);
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

test('recovery code regeneration returns a specific message when TOTP code was recently consumed', function () {
    $user = User::factory()->create([
        'email' => 'mfa-replay-regenerate@secpal.dev',
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    // Clear the cache entry left by confirmTwoFactorAuth so we start fresh
    Cache::flush();

    $this->actingAs($user->fresh(), 'sanctum');

    // Consume a TOTP code via validation (simulates a recent MFA verify)
    $freshCode = $user->fresh()->makeTwoFactorCode();
    expect($user->fresh()->twoFactorAuth->validateCode($freshCode))->toBeTrue();

    // Immediately attempt recovery regeneration with the same code — anti-replay blocks it
    $this->postJson('/v1/me/mfa/recovery-codes/regenerate', [
        'method' => 'totp',
        'code' => $freshCode,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['code'])
        ->assertJson([
            'errors' => [
                'code' => ['This code was already used recently. Please wait for a new code from your authenticator app.'],
            ],
        ]);
});

test('recovery code regeneration succeeds with a TOTP code that was not recently consumed', function () {
    $user = User::factory()->create([
        'email' => 'mfa-noreplay-regenerate@secpal.dev',
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    // Clear the cache entry left by confirmTwoFactorAuth so the current code is usable
    Cache::flush();

    $this->actingAs($user->fresh(), 'sanctum');

    $response = $this->postJson('/v1/me/mfa/recovery-codes/regenerate', [
        'method' => 'totp',
        'code' => $user->fresh()->makeTwoFactorCode(),
    ]);

    $response->assertOk();
    expect($response->json('data.recovery_codes.codes'))->toHaveCount(10);
});

test('disabling MFA returns a specific message when TOTP code was recently consumed', function () {
    $user = User::factory()->create([
        'email' => 'mfa-replay-disable@secpal.dev',
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    // Clear the cache entry left by confirmTwoFactorAuth so we start fresh
    Cache::flush();

    $this->actingAs($user->fresh(), 'sanctum');

    // Consume a TOTP code
    $freshCode = $user->fresh()->makeTwoFactorCode();
    expect($user->fresh()->twoFactorAuth->validateCode($freshCode))->toBeTrue();

    // Immediately attempt to disable with the same code
    $this->deleteJson('/v1/me/mfa', [
        'method' => 'totp',
        'code' => $freshCode,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['code'])
        ->assertJson([
            'errors' => [
                'code' => ['This code was already used recently. Please wait for a new code from your authenticator app.'],
            ],
        ]);
});
