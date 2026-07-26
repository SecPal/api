<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\InjectTenantId;
use App\Http\Middleware\RestoreSessionFromRememberToken;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Models\Employee;
use App\Models\EmployeeOnboardingToken;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('validates token with correct email and returns minimal data only', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = $this->withHeaders(spaHeaders())
        ->getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email='.urlencode('test@secpal.dev'));

    $response->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', spaOrigin())
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertExactJson([
            'data' => [
                'valid' => true,
            ],
        ]);

    expectNoSetCookieHeaders($response);
});

test('token validation excludes browser identity middleware while completion keeps its session and csrf lifecycle', function (): void {
    /** @var Router $router */
    $router = app('router');

    $validationRoute = $router->getRoutes()->match(
        Request::create('/v1/onboarding/validate-token', 'GET')
    );
    $validationMiddleware = $router->gatherRouteMiddleware($validationRoute);

    expect($validationMiddleware)
        ->not->toContain(EnsureFrontendRequestsAreStateful::class)
        ->not->toContain(RestoreSessionFromRememberToken::class)
        ->not->toContain(SetLocaleFromHeader::class)
        ->not->toContain(InjectTenantId::class)
        ->toContain(ForceJsonResponse::class)
        ->toContain(ThrottleRequests::class.':onboarding-validate');

    $completionRoute = $router->getRoutes()->match(
        Request::create('/v1/onboarding/complete', 'POST')
    );

    expect($router->gatherRouteMiddleware($completionRoute))
        ->toContain(EnsureFrontendRequestsAreStateful::class)
        ->toContain(RestoreSessionFromRememberToken::class)
        ->toContain(StartSession::class)
        ->toContain(PreventRequestForgery::class)
        ->toContain(ThrottleRequests::class.':onboarding-complete');
});

test('SECURITY: validate-token does not leak personal data even when token is valid', function () {
    // An attacker who intercepts the magic link must NOT be able to learn the
    // invitee's first or last name from the public endpoint. Only the user who
    // proves knowledge of name + date of birth at POST /onboarding/complete may
    // proceed; the validation endpoint must stay information-poor.
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'leak-check@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'SuperSecret',
        'last_name' => 'NeverLeaked',
        'email' => 'leak-check@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email='.urlencode('leak-check@secpal.dev'));

    $response->assertOk()
        ->assertJsonMissingPath('data.first_name')
        ->assertJsonMissingPath('data.last_name')
        ->assertJsonMissingPath('data.email');

    $body = $response->getContent();
    expect($body)->not->toContain('SuperSecret')
        ->and($body)->not->toContain('NeverLeaked');
});

test('SECURITY: rejects valid token with wrong email', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'correct@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'email' => 'correct@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Attacker tries to use valid token with different email
    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email='.urlencode('attacker@secpal.dev'));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('SECURITY: validates email case-sensitively', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Try with different case
    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email='.urlencode('TEST@SECPAL.DEV'));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
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
    $response = getJson('/v1/onboarding/validate-token?email=test@secpal.dev');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['token']);
});

test('rejects invalid token', function () {
    $response = getJson('/v1/onboarding/validate-token?token=invalid-token&email=test@secpal.dev');

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('rejects invalid token with localized message when german locale is requested', function () {
    $response = $this->withHeaders([
        'Accept-Language' => 'de',
    ])->getJson('/v1/onboarding/validate-token?token=invalid-token&email=test@secpal.dev');

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Ungültiger oder abgelaufener Onboarding-Link. Bitte fordern Sie eine neue Einladung an.',
        ]);
});

test('validation errors remain stateless for the configured spa origin', function (): void {
    $response = $this->withHeaders(spaHeaders([
        'Accept-Language' => 'de',
    ]))->getJson('/v1/onboarding/validate-token?email=synthetic-invitee@secpal.dev');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['token'])
        ->assertHeader('Access-Control-Allow-Origin', spaOrigin())
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Type', 'application/json');

    expect(app()->getLocale())->toBe('de');
    expectNoSetCookieHeaders($response);
});

test('token validation does not refresh synthetic incoming session or xsrf cookies', function (): void {
    $response = $this->withCredentials()
        ->withCookies([
            (string) config('session.cookie') => 'synthetic-session-cookie',
            SPA_XSRF_COOKIE_NAME => 'synthetic-xsrf-cookie',
        ])->withHeaders(spaHeaders())
        ->getJson('/v1/onboarding/validate-token?token=synthetic-invalid-token&email=synthetic-invitee@secpal.dev');

    $response->assertUnprocessable();

    expectNoSetCookieHeaders($response);
});

test('token validation does not restore a synthetic remember-token identity', function (): void {
    $rememberToken = 'synthetic-onboarding-remember-token';
    $user = User::factory()->create([
        'remember_token' => $rememberToken,
    ]);
    $rememberCookieName = 'remember_web_'.sha1(SessionGuard::class);

    Event::fake([Login::class]);

    $response = $this->withCredentials()
        ->withCookies([
            $rememberCookieName => $user->getAuthIdentifier().'|'.$rememberToken.'|synthetic-password-hash',
        ])->withHeaders(spaHeaders())
        ->getJson('/v1/onboarding/validate-token?token=synthetic-invalid-token&email=synthetic-invitee@secpal.dev');

    $response->assertUnprocessable();

    Event::assertNotDispatched(Login::class);
    expectNoSetCookieHeaders($response);
});

test('token validation does not authenticate or touch a synthetic bearer token', function (): void {
    $user = User::factory()->create();
    $accessToken = $user->createToken('synthetic-onboarding-validation');

    Event::fake([TokenAuthenticated::class]);

    $response = $this->withToken($accessToken->plainTextToken)
        ->withHeaders(spaHeaders())
        ->getJson('/v1/onboarding/validate-token?token=synthetic-invalid-token&email=synthetic-invitee@secpal.dev');

    $response->assertUnprocessable();

    Event::assertNotDispatched(TokenAuthenticated::class);
    expect($accessToken->accessToken->fresh()->last_used_at)->toBeNull();
    expectNoSetCookieHeaders($response);
});

test('rejects expired token', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Expire token
    $tokenData['model']->update(['expires_at' => now()->subDay()]);

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email=test@secpal.dev');

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('rejects already completed token', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Mark token as completed
    $tokenData['model']->markAsCompleted('127.0.0.1', 'test-agent');

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email=test@secpal.dev');

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('rejects invalidated (burned) token', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    // Simulate a burned link (failed identity proof)
    $tokenData['model']->markAsInvalidated('127.0.0.1', 'test-agent', 'identity_verification_failed');

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email=test@secpal.dev');

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Invalid or expired onboarding link. Please request a new invitation.',
        ]);
});

test('rejects token for non-pre-contract employee', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'test@secpal.dev',
        'user_id' => $user->id,
        'status' => Employee::STATUS_ACTIVE, // Not PRE_CONTRACT
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email=test@secpal.dev');

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Onboarding is only available for pre-contract employees.',
        ]);
});

test('handles URL-encoded special characters in email', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'test+tag@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test+tag@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email='.urlencode('test+tag@secpal.dev'));

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                'valid' => true,
            ],
        ]);
});

test('does not rate limit repeated successful token validations', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'repeat@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Repeat',
        'last_name' => 'Visitor',
        'email' => 'repeat@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    for ($i = 0; $i < 6; $i++) {
        $response = getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email='.urlencode('repeat@secpal.dev'));

        $response->assertOk();
    }
});

test('rate limits repeated failed validation attempts', function () {
    // Failed validations should still be throttled to slow down abuse.
    for ($i = 0; $i < 4; $i++) {
        $response = getJson('/v1/onboarding/validate-token?token=invalid-token&email=test@secpal.dev');
    }

    // 4th request should be rate limited
    $response->assertStatus(429)
        ->assertJson([
            'message' => 'Too many onboarding attempts. Please try again later.',
        ]);
});

test('failed validation attempts for one email do not throttle a different invitee on the same IP', function () {
    for ($i = 0; $i < 4; $i++) {
        $response = getJson('/v1/onboarding/validate-token?token=invalid-token&email=blocked@secpal.dev');
    }

    $response->assertStatus(429);

    /** @var User $user */
    $user = User::factory()->create([
        'email' => 'fresh@secpal.dev',
    ]);

    /** @var Employee $employee */
    $employee = Employee::factory()->preContract()->create([
        'first_name' => 'Fresh',
        'last_name' => 'Invitee',
        'email' => 'fresh@secpal.dev',
        'user_id' => $user->id,
    ]);

    $tokenData = EmployeeOnboardingToken::generate($employee);
    $plainToken = $tokenData['plain'];

    getJson('/v1/onboarding/validate-token?token='.urlencode($plainToken).'&email=fresh@secpal.dev')
        ->assertOk();
});
