<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\InjectTenantId;
use App\Http\Middleware\RestoreSessionFromRememberToken;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

uses(RefreshDatabase::class);

/**
 * @return non-empty-string
 */
function signedEmailVerificationPath(User $user): string
{
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $path = (string) parse_url($url, PHP_URL_PATH);
    $query = (string) parse_url($url, PHP_URL_QUERY);

    return "{$path}?{$query}";
}

test('signed email verification uses its signed link without browser identity middleware', function (): void {
    /** @var Router $router */
    $router = app('router');
    $route = $router->getRoutes()->match(
        Request::create(
            '/v1/auth/email/verify/00000000-0000-4000-8000-000000000001/synthetic-hash',
            'GET',
        ),
    );

    expect($router->gatherRouteMiddleware($route))
        ->not->toContain(EnsureFrontendRequestsAreStateful::class)
        ->not->toContain(RestoreSessionFromRememberToken::class)
        ->not->toContain(SetLocaleFromHeader::class)
        ->not->toContain(InjectTenantId::class)
        ->toContain(ForceJsonResponse::class)
        ->toContain(ValidateSignature::class)
        ->toContain(ThrottleRequests::class.':email-verification');
});

test('successful signed email verification is stateless and preserves response middleware', function (): void {
    $user = User::factory()->unverified()->create([
        'email' => 'synthetic-verification-success@secpal.dev',
    ]);

    $response = $this->withHeaders(spaHeaders([
        'Accept-Language' => 'de',
    ]))->getJson(signedEmailVerificationPath($user));

    $response->assertOk()
        ->assertJson([
            'message' => 'E-Mail-Adresse erfolgreich verifiziert.',
        ])
        ->assertHeader('Access-Control-Allow-Origin', spaOrigin())
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Frame-Options', 'DENY');

    expect(app()->getLocale())->toBe('de')
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();
    expectNoSetCookieHeaders($response);
});

test('signed email verification remains limited to six requests per minute', function (): void {
    $user = User::factory()->unverified()->create([
        'email' => 'synthetic-throttled-verification@secpal.dev',
    ]);
    $path = signedEmailVerificationPath($user);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $response = $this->withHeaders(spaHeaders())->getJson($path);

        $response->assertOk();
        expectNoSetCookieHeaders($response);
    }

    $response = $this->withHeaders(spaHeaders())->getJson($path);

    $response->assertTooManyRequests()
        ->assertHeader('Content-Type', 'application/json');
    expectNoSetCookieHeaders($response);
});

test('invalid email verification signatures stay stateless for the configured spa origin', function (): void {
    $response = $this->withCredentials()
        ->withCookies([
            (string) config('session.cookie') => 'synthetic-session-cookie',
            SPA_XSRF_COOKIE_NAME => 'synthetic-xsrf-cookie',
        ])->withHeaders(spaHeaders([
            'Accept-Language' => 'de',
        ]))->getJson(
            '/v1/auth/email/verify/00000000-0000-4000-8000-000000000002/synthetic-hash'
            .'?expires=2000000000&signature=synthetic-invalid-signature',
        );

    $response->assertForbidden()
        ->assertHeader('Access-Control-Allow-Origin', spaOrigin())
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Frame-Options', 'DENY');

    expect(app()->getLocale())->toBe('de');
    expectNoSetCookieHeaders($response);
});

test('signed email verification does not restore a remember-token identity', function (): void {
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

    $response = $this->withCredentials()
        ->withCookies([
            $rememberCookieName => $rememberedUser->getAuthIdentifier()
                .'|'.$rememberToken
                .'|'.$rememberedUser->getAuthPassword(),
        ])->withHeaders(spaHeaders())
        ->getJson(signedEmailVerificationPath($verificationUser));

    $response->assertOk();

    Event::assertNotDispatched(Login::class);
    expect($verificationUser->fresh()->hasVerifiedEmail())->toBeTrue();
    expectNoSetCookieHeaders($response);
});

test('signed email verification does not authenticate or touch a bearer token', function (): void {
    $tokenUser = User::factory()->create([
        'email' => 'synthetic-token-user@secpal.dev',
    ]);
    $accessToken = $tokenUser->createToken('synthetic-email-verification');
    $verificationUser = User::factory()->unverified()->create([
        'email' => 'synthetic-token-verification@secpal.dev',
    ]);

    Event::fake([TokenAuthenticated::class]);

    $response = $this->withToken($accessToken->plainTextToken)
        ->withHeaders(spaHeaders())
        ->getJson(signedEmailVerificationPath($verificationUser));

    $response->assertOk();

    Event::assertNotDispatched(TokenAuthenticated::class);
    expect($accessToken->accessToken->fresh()->last_used_at)->toBeNull()
        ->and($verificationUser->fresh()->hasVerifiedEmail())->toBeTrue();
    expectNoSetCookieHeaders($response);
});
