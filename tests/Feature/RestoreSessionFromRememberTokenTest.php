<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

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

        // Login the user
        Auth::guard('web')->login($user, remember: true);

        // Create a request with session
        $request = Request::create('/v1/me', 'GET');
        $request->setLaravelSession(app('session.store'));

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
});

describe('Session Restoration Integration', function () {
    test('middleware is registered in api middleware stack', function () {
        // Verify the middleware is registered by checking the kernel
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $middlewareGroups = $kernel->getMiddlewareGroups();

        // The RestoreSessionFromRememberToken should be in the api group
        expect($middlewareGroups['api'])->toContain(
            \App\Http\Middleware\RestoreSessionFromRememberToken::class
        );
    });
});
