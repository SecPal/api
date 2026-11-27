<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Jobs\DeliverGlobalIdentityOperation;
use App\Models\GlobalIdentityOperation;
use App\Models\User;
use App\Services\GlobalIdentity\GlobalIdentityAccounts;
use App\Services\GlobalIdentity\GlobalIdentityOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('installs the authoritative encrypted identity schema through ordinary migrations', function (): void {
    expect(Schema::hasColumns('users', [
        'email_enc', 'email_idx', 'email_normalization_version', 'email_idx_key_version', 'email_version',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('users', 'email'))->toBeFalse()
        ->and(Schema::hasTable('password_reset_tokens'))->toBeFalse()
        ->and(Schema::hasTable('global_identity_email_reservations'))->toBeTrue();
});

it('creates the fixture identity through exact index lookup and keeps model serialization redacted', function (): void {
    $user = User::factory()->create(['email' => 'Sample+Test@secpal.dev']);

    expect(app(GlobalIdentityAccounts::class)->find('sample+test@SECPAL.dev')?->id)->toBe($user->id)
        ->and($user->emailForDisplay())->toBe('Sample+Test@secpal.dev')
        ->and($user->toJson())->not->toContain('email_enc', 'email_idx', 'Sample+Test');
});

it('accepts a neutral password reset and consumes its operation only once', function (): void {
    $user = User::factory()->create(['email' => 'reset@secpal.dev']);
    Queue::fake();

    $this->postJson('/v1/auth/password-reset-requests', ['email' => 'RESET@secpal.dev'])
        ->assertAccepted()->assertExactJson(['message' => 'Request accepted.']);
    $this->postJson('/v1/auth/password-reset-requests', ['email' => 'unknown@secpal.dev'])
        ->assertAccepted()->assertExactJson(['message' => 'Request accepted.']);
    $operation = GlobalIdentityOperation::query()->where('user_id', $user->id)->sole();
    Queue::assertPushed(DeliverGlobalIdentityOperation::class, fn (DeliverGlobalIdentityOperation $job): bool => $job->operationId === $operation->id);
    $token = app(GlobalIdentityOperationService::class)->openToken($operation);
    $payload = ['token' => $token, 'password' => 'Synthetic-Test-Password-642!'];
    $path = '/v1/auth/password-reset-requests/'.$operation->id.'/consume';
    $this->postJson($path, $payload)->assertNoContent();
    $this->postJson($path, $payload)->assertConflict();
});

it('activates the reserved address only after proof and revokes affected credentials', function (): void {
    config(['global_identity.mailer' => 'array']);
    Queue::fake();
    $user = User::factory()->create(['email' => 'previous@secpal.dev', 'remember_token' => 'synthetic-remember']);
    $user->issueApiToken('synthetic-native-device');
    $this->actingAs($user);
    $proof = ['current_password' => 'password', 'second_factor' => ['method' => 'totp', 'code' => '123456']];
    $this->postJson('/v1/me/email-change-requests', ['email' => 'successor@secpal.dev', ...$proof])->assertForbidden();
    expect(GlobalIdentityOperation::query()->count())->toBe(0);

    $user->createTwoFactorAuth();
    $user->enableTwoFactorAuth();
    $proof['second_factor']['code'] = $user->twoFactorAuth->makeCode();
    $this->postJson('/v1/me/email-change-requests', ['email' => 'successor@secpal.dev', ...$proof])->assertAccepted();
    $operation = GlobalIdentityOperation::query()->sole();
    $service = app(GlobalIdentityOperationService::class);
    $token = $service->openToken($operation);
    expect($user->fresh()->emailForDisplay())->toBe('previous@secpal.dev')
        ->and(DB::table('global_identity_email_reservations')->where('operation_id', $operation->id)->exists())->toBeTrue();
    $claim = $service->claim($operation->id, 'fixture');
    expect($claim)->not->toBeNull()->and($service->commitDispatch($claim))->toBeTrue();
    $service->recordResult($claim, new App\Services\GlobalIdentity\GlobalIdentityDeliveryResult(
        App\Services\GlobalIdentity\GlobalIdentityDeliveryOutcome::Accepted,
    ));
    $this->postJson('/v1/auth/email-change-requests/'.$operation->id.'/consume', ['token' => $token])->assertNoContent();
    $user->refresh();
    expect($user->emailForDisplay())->toBe('successor@secpal.dev')
        ->and($user->email_version)->toBe(2)
        ->and($user->remember_token)->toBeNull()
        ->and($user->tokens()->count())->toBe(0)
        ->and(DB::table('global_identity_email_reservations')->where('operation_id', $operation->id)->exists())->toBeFalse();
});

it('rejects a logging mailer before an identity message is handed off', function (): void {
    config(['global_identity.mailer' => 'log']);
    expect(fn () => app(App\Services\GlobalIdentity\IdentityMailDelivery::class)->mailerName())
        ->toThrow(App\Exceptions\GlobalIdentityKeyException::class);
});
