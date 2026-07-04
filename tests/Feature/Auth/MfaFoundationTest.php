<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laragear\TwoFactor\Models\TwoFactorAuthentication;

uses(RefreshDatabase::class);

afterEach(function () {
    TwoFactorAuthentication::generateRecoveryCodesUsing();
    Cache::flush();
});

test('user can prepare and confirm a two-factor enrollment', function () {
    $user = User::factory()->create([
        'email' => 'mfa-enrollment@secpal.dev',
    ]);

    $secret = $user->createTwoFactorAuth();
    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->hasPendingTwoFactorEnrollment())->toBeTrue()
        ->and($user->twoFactorAuth->exists)->toBeTrue()
        ->and($secret->toUri())->toContain('otpauth://totp/')
        ->and($secret->toString())->not->toBe('');

    $code = $user->makeTwoFactorCode();

    expect($user->confirmTwoFactorAuth($code))->toBeTrue();

    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->hasPendingTwoFactorEnrollment())->toBeFalse()
        ->and($user->getRemainingTwoFactorRecoveryCodesCount())->toBe(10)
        ->and($user->getTwoFactorRecoveryCodesGeneratedAt())->not->toBeNull();
});

test('recovery codes are one-time credentials and reduce the remaining count when consumed', function () {
    $user = User::factory()->create([
        'email' => 'mfa-recovery@secpal.dev',
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $recoveryCode = $user->getRecoveryCodes()->firstWhere('used_at', null)['code'];

    expect($user->validateTwoFactorCode($recoveryCode))->toBeTrue();

    $user->refresh();

    expect($user->getRemainingTwoFactorRecoveryCodesCount())->toBe(9)
        ->and($user->validateTwoFactorCode($recoveryCode))->toBeFalse();
});

test('regenerating recovery codes replaces the previous batch', function () {
    $counter = 0;

    TwoFactorAuthentication::generateRecoveryCodesUsing(function (int $length) use (&$counter): string {
        return str_pad((string) ++$counter, $length, 'A', STR_PAD_LEFT);
    });

    $user = User::factory()->create([
        'email' => 'mfa-rotation@secpal.dev',
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $firstBatch = $user->getRecoveryCodes()->pluck('code')->all();

    $regenerated = $user->generateRecoveryCodes();
    $user->refresh();

    expect($regenerated->pluck('code')->all())->not->toEqual($firstBatch)
        ->and($user->getRecoveryCodes()->pluck('code')->all())->not->toEqual($firstBatch)
        ->and($user->getRemainingTwoFactorRecoveryCodesCount())->toBe(10);
});

test('disabling two-factor authentication clears the persisted authentication record', function () {
    $user = User::factory()->create([
        'email' => 'mfa-disable@secpal.dev',
    ]);

    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $oldSecret = $user->twoFactorAuth->shared_secret;

    $user->disableTwoFactorAuth();
    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->hasPendingTwoFactorEnrollment())->toBeFalse()
        ->and($user->twoFactorAuth->exists)->toBeFalse()
        ->and($user->getRecoveryCodes())->toHaveCount(0);

    $newSecret = $user->createTwoFactorAuth()->toString();

    expect($newSecret)->not->toBe($oldSecret)
        ->and($user->fresh()->hasPendingTwoFactorEnrollment())->toBeTrue();
});
