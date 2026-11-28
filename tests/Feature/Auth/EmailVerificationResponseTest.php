<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\InjectTenantId;
use App\Http\Middleware\RestoreSessionFromRememberToken;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Models\GlobalIdentityOperation;
use App\Models\User;
use App\Services\GlobalIdentity\GlobalIdentityOperationContext;
use App\Services\GlobalIdentity\GlobalIdentityOperationService;
use App\Services\GlobalIdentity\GlobalIdentityOperationType;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

uses(RefreshDatabase::class);

function postEmailVerification($test, User $user)
{
    $id = (string) Str::uuid();
    $operations = app(GlobalIdentityOperationService::class);
    $operations->create(
        new GlobalIdentityOperationContext(GlobalIdentityOperationType::EmailVerification, $id, $user->id, $user->email_version),
        now()->toImmutable()->addMinute(),
    );

    return $test->postJson('/v1/auth/email/verifications/'.$id.'/consume', [
        'token' => $operations->openToken(GlobalIdentityOperation::query()->findOrFail($id)),
    ]);
}

test('operation-based email verification uses mailbox proof without browser identity middleware', function (): void {
    /** @var Router $router */
    $router = app('router');
    $route = $router->getRoutes()->match(
        Request::create(
            '/v1/auth/email/verifications/00000000-0000-4000-8000-000000000001/consume',
            'POST',
        ),
    );

    expect($router->gatherRouteMiddleware($route))
        ->not->toContain(EnsureFrontendRequestsAreStateful::class)
        ->not->toContain(RestoreSessionFromRememberToken::class)
        ->not->toContain(SetLocaleFromHeader::class)
        ->not->toContain(InjectTenantId::class)
        ->toContain(ForceJsonResponse::class)
        ->toContain(ThrottleRequests::class.':email-verification');
});

test('successful operation-based email verification is stateless and preserves response middleware', function (): void {
    $user = User::factory()->unverified()->create([
        'email' => 'synthetic-verification-success@secpal.dev',
    ]);

    $response = postEmailVerification($this->withHeaders(spaHeaders(['Accept-Language' => 'de'])), $user);

    $response->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', spaOrigin())
        ->assertHeader('X-Frame-Options', 'DENY');

    expect(app()->getLocale())->toBe('de')
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();
    expectNoSetCookieHeaders($response);
});

test('operation-based email verification remains limited to six requests per minute', function (): void {
    $user = User::factory()->unverified()->create([
        'email' => 'synthetic-throttled-verification@secpal.dev',
    ]);
    $path = "/v1/auth/email/verifications/{$user->id}/consume";

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $response = $this->withHeaders(spaHeaders())->postJson($path, ['token' => 'invalid']);

        $response->assertConflict();
        expectNoSetCookieHeaders($response);
    }

    $response = $this->withHeaders(spaHeaders())->postJson($path, ['token' => 'invalid']);

    $response->assertTooManyRequests()
        ->assertHeader('Content-Type', 'application/json');
    expectNoSetCookieHeaders($response);
});

test('invalid email verification proofs stay stateless for the configured spa origin', function (): void {
    $response = $this->withCredentials()
        ->withCookies([
            (string) config('session.cookie') => 'synthetic-session-cookie',
            SPA_XSRF_COOKIE_NAME => 'synthetic-xsrf-cookie',
        ])->withHeaders(spaHeaders([
            'Accept-Language' => 'de',
        ]))->postJson(
            '/v1/auth/email/verifications/00000000-0000-4000-8000-000000000002/consume',
            ['token' => 'invalid'],
        );

    $response->assertConflict()
        ->assertHeader('Access-Control-Allow-Origin', spaOrigin())
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Frame-Options', 'DENY');

    expect(app()->getLocale())->toBe('de');
    expectNoSetCookieHeaders($response);
});

test('operation-based email verification does not restore a remember-token identity', function (): void {
    $rememberToken = 'synthetic-email-verification-remember-token';
    $rememberedUser = User::factory()->create([
        'email' => 'synthetic-remembered-user@secpal.dev',
        'password' => bcrypt('synthetic-password'),
        'remember_token' => $rememberToken,
    ]);
    $verificationUser = User::factory()->unverified()->create([
        'email' => 'synthetic-remember-verification@secpal.dev',
    ]);

    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');
    $rememberCookieName = $guard->getRecallerName();

    Event::fake([Login::class]);

    $response = postEmailVerification($this->withCredentials()
        ->withCookies([
            $rememberCookieName => $rememberedUser->getAuthIdentifier()
                .'|'.$rememberToken
                .'|'.$rememberedUser->getAuthPassword(),
        ])->withHeaders(spaHeaders()), $verificationUser);

    $response->assertNoContent();

    Event::assertNotDispatched(Login::class);
    expect($verificationUser->fresh()->hasVerifiedEmail())->toBeTrue();
    expectNoSetCookieHeaders($response);
});

test('operation-based email verification does not authenticate or touch a bearer token', function (): void {
    $tokenUser = User::factory()->create([
        'email' => 'synthetic-token-user@secpal.dev',
    ]);
    $accessToken = $tokenUser->createToken('synthetic-email-verification');
    $verificationUser = User::factory()->unverified()->create([
        'email' => 'synthetic-token-verification@secpal.dev',
    ]);

    Event::fake([TokenAuthenticated::class]);

    $response = postEmailVerification($this->withToken($accessToken->plainTextToken)->withHeaders(spaHeaders()), $verificationUser);

    $response->assertNoContent();

    Event::assertNotDispatched(TokenAuthenticated::class);
    expect($accessToken->accessToken->fresh()->last_used_at)->toBeNull()
        ->and($verificationUser->fresh()->hasVerifiedEmail())->toBeTrue();
    expectNoSetCookieHeaders($response);
});
