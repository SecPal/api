<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Http\Middleware\RestoreSessionFromRememberToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

describe('RestoreSessionFromRememberToken Middleware', function () {
    test('does not interfere when user is already authenticated', function () {
        $user = User::factory()->create();

        // Login the user (creates session)
        Auth::guard('web')->login($user, remember: true);

        // Create a request with session
        $request = Request::create('/v1/me', 'GET');
        $request->setLaravelSession(app('session.store'));

        // Note: We don't set the remember cookie here because the middleware's
        // first condition (Auth::guard('web')->check()) will be true, so it
        // correctly skips processing - which is the behavior we want to test.

        $middleware = new RestoreSessionFromRememberToken;
        $response = $middleware->handle($request, fn () => new Response('OK'));

        expect($response->getContent())->toBe('OK');
        expect(Auth::guard('web')->check())->toBeTrue();
    });

    test('passes through when no session exists', function () {
        // Create a request WITHOUT session (non-stateful request)
        $request = Request::create('/v1/me', 'GET');

        $middleware = new RestoreSessionFromRememberToken;
        $response = $middleware->handle($request, fn () => new Response('OK'));

        expect($response->getContent())->toBe('OK');
    });

    test('passes through when no remember cookie exists', function () {
        // Create a request with session but no remember cookie
        $request = Request::create('/v1/me', 'GET');
        $request->setLaravelSession(app('session.store'));

        $middleware = new RestoreSessionFromRememberToken;
        $response = $middleware->handle($request, fn () => new Response('OK'));

        expect($response->getContent())->toBe('OK');
        expect(Auth::guard('web')->check())->toBeFalse();
    });

    test('attempts session restoration when remember cookie exists but no active session', function () {
        $user = User::factory()->create();

        // Create a request with session but NOT authenticated
        $request = Request::create('/v1/me', 'GET');
        $request->setLaravelSession(app('session.store'));

        // Set remember cookie on request (simulating browser sending remember cookie)
        /** @var Illuminate\Auth\SessionGuard $guard */
        $guard = Auth::guard('web');
        $cookieName = $guard->getRecallerName();
        $request->cookies->set($cookieName, 'some-remember-token-value');

        // The middleware will attempt to restore the session via Auth::guard('web')->user()
        // which calls loginByRememberToken() internally. Since we don't have a valid
        // remember token in the DB matching the cookie, it won't authenticate,
        // but the important thing is that the middleware attempts the restoration.
        $middleware = new RestoreSessionFromRememberToken;
        $response = $middleware->handle($request, fn () => new Response('OK'));

        expect($response->getContent())->toBe('OK');
        // User not authenticated because the fake cookie doesn't match a real remember token
        expect(Auth::guard('web')->check())->toBeFalse();
    });
});

describe('Session Restoration Integration', function () {
    test('middleware is registered in api middleware stack', function () {
        // Verify the middleware is registered by checking the kernel
        $kernel = app(Illuminate\Contracts\Http\Kernel::class);
        $middlewareGroups = $kernel->getMiddlewareGroups();

        // The RestoreSessionFromRememberToken should be in the api group
        expect($middlewareGroups['api'])->toContain(
            RestoreSessionFromRememberToken::class
        );
    });

    test('full flow: user stays authenticated after session expires with remember token', function () {
        // Step 1: Create user and login via HTTP (like real SPA)
        $user = User::factory()->create([
            'email' => 'remember-flow@example.com',
            'password' => bcrypt('password123'),
        ]);

        // Step 2: Login via SPA endpoint (sets remember token + session + remember cookie)
        $loginResponse = $this->withHeaders(spaHeaders([
            'X-XSRF-TOKEN' => issueSpaCsrfToken($this),
        ]))->postJson('/v1/auth/login', [
            'email' => 'remember-flow@example.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertOk();

        // Verify remember token was set in database
        $user->refresh();
        expect($user->remember_token)->not->toBeNull();

        // Step 3: Verify we can access protected endpoint
        $this->withHeaders(spaHeaders())->getJson('/v1/me')
            ->assertOk()
            ->assertJson(['email' => 'remember-flow@example.com']);

        // Step 4: Simulate session expiry by invalidating session
        // (In real scenario this happens after SESSION_LIFETIME minutes)
        // The test framework maintains cookies between requests, including the remember cookie
        session()->invalidate();
        session()->regenerateToken();

        // Step 5: Make another request - middleware should restore session from remember cookie
        // Note: In Laravel's test environment, the remember cookie is maintained
        // and the middleware + SessionGuard will restore the authentication
        $this->withHeaders(spaHeaders())->getJson('/v1/me')
            ->assertOk()
            ->assertJson(['email' => 'remember-flow@example.com']);
    });
});
