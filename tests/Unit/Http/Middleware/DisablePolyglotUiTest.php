<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 SecPal Contributors

declare(strict_types=1);

use App\Http\Middleware\DisablePolyglotUi;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @property DisablePolyglotUi $middleware
 */
beforeEach(function () {
    $this->middleware = new DisablePolyglotUi;
});

test('blocks the polyglot ui in production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    $request = Request::create('/polyglot', 'GET');

    expect(fn () => $this->middleware->handle($request, fn () => new Response('ok')))
        ->toThrow(NotFoundHttpException::class);
});

test('allows the polyglot ui outside production', function () {
    $this->app->detectEnvironment(fn (): string => 'local');

    $request = Request::create('/polyglot', 'GET');
    $response = new Response('ok');

    $result = $this->middleware->handle($request, fn () => $response);

    expect($result)->toBe($response);
});
