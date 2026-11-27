<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\GlobalIdentityOperation;
use App\Models\User;
use App\Services\GlobalIdentity\GlobalIdentityDeliveryState;
use App\Services\GlobalIdentity\GlobalIdentityOperationContext;
use App\Services\GlobalIdentity\GlobalIdentityOperationService;
use App\Services\GlobalIdentity\GlobalIdentityOperationType;
use App\Services\GlobalIdentity\IdentityLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/** @return array{string,string} */
function issueResetProof(User $user): array
{
    $id = (string) Str::uuid();
    $service = app(GlobalIdentityOperationService::class);
    $service->create(
        new GlobalIdentityOperationContext(GlobalIdentityOperationType::PasswordReset, $id, $user->id, $user->email_version),
        now()->toImmutable()->addMinute(),
    );

    return ['/v1/auth/password-reset-requests/'.$id.'/consume', $service->openToken(GlobalIdentityOperation::query()->findOrFail($id))];
}

it('replaces the password and revokes other proofs and credentials atomically', function (): void {
    $user = User::factory()->create(['remember_token' => 'retained-fixture']);
    $user->issueApiToken('native-fixture');
    DB::table('sessions')->insert([
        'id' => 'synthetic-password-reset-session', 'user_id' => $user->id, 'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
    [$path, $token] = issueResetProof($user);
    [$otherPath, $otherToken] = issueResetProof($user);
    $password = 'Synthetic-Password-Reset-642!';
    $this->postJson($path, ['token' => $token, 'password' => $password])->assertNoContent();
    $this->postJson($path, ['token' => $token, 'password' => $password])->assertConflict();
    $this->postJson($otherPath, ['token' => $otherToken, 'password' => $password])->assertConflict();
    $user->refresh();
    expect(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and($user->remember_token)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    $activity = Activity::query()->where('log_name', 'authentication')
        ->where('description', 'User reset password and revoked active sessions')
        ->where('causer_id', $user->id)->sole();
    expect($activity->properties?->all())->toBe(['event' => 'password_reset', 'user_id' => $user->id]);
});

it('rejects invalid proof without changing the password', function (): void {
    $user = User::factory()->create();
    [$path] = issueResetProof($user);
    $this->postJson($path, ['token' => 'wrong', 'password' => 'Synthetic-Password-Reset-642!'])->assertConflict();
    expect($user->fresh()->password)->toBe($user->password);
});

it('revokes the pending email change and releases its reservation on password reset', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    app(IdentityLifecycle::class)->request($user, GlobalIdentityOperationType::PendingEmailChange, 'candidate@secpal.dev');
    $change = GlobalIdentityOperation::query()->sole();
    expect(DB::table('global_identity_email_reservations')->where('operation_id', $change->id)->exists())->toBeTrue();
    [$path, $token] = issueResetProof($user);

    $this->postJson($path, ['token' => $token, 'password' => 'Synthetic-Password-Reset-642!'])->assertNoContent();

    expect($change->refresh()->delivery_state)->toBe(GlobalIdentityDeliveryState::Terminal)
        ->and($change->token_hash)->toBeNull()
        ->and($change->recipient_enc)->toBeNull()
        ->and(DB::table('global_identity_email_reservations')->where('operation_id', $change->id)->exists())->toBeFalse();
});

it('keeps mailbox proof consumption independent of SPA session middleware', function (string $route): void {
    $router = app(Router::class);
    $matched = $router->getRoutes()->match(Request::create($route, 'POST'));
    expect($router->gatherRouteMiddleware($matched))->not->toContain(EnsureFrontendRequestsAreStateful::class);
})->with([
    '/v1/auth/password-reset-requests/operation/consume',
    '/v1/auth/email/verifications/operation/consume',
    '/v1/auth/email-change-requests/operation/consume',
]);

it('rejects expired proof without changing the password', function (): void {
    $user = User::factory()->create();
    [$path, $token] = issueResetProof($user);
    $this->travel(2)->minutes();
    $this->postJson($path, ['token' => $token, 'password' => 'Synthetic-Password-Reset-642!'])->assertConflict();
    expect($user->fresh()->password)->toBe($user->password);
});

it('validates the reset payload before looking up proof', function (): void {
    $path = '/v1/auth/password-reset-requests/'.Str::uuid().'/consume';
    $this->postJson($path, [])->assertUnprocessable()->assertJsonValidationErrors(['token', 'password']);
    $this->postJson($path, ['token' => 'wrong', 'password' => 'weak'])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
});
