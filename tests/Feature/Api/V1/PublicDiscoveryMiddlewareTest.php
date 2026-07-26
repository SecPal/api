<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\InjectTenantId;
use App\Http\Middleware\RestoreSessionFromRememberToken;
use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

test('public discovery routes exclude identity-resolving middleware while retaining public api behavior', function (string $path, string $throttleName): void {
    /** @var Router $router */
    $router = app('router');
    $route = $router->getRoutes()->match(Request::create($path, 'GET'));
    $middleware = $router->gatherRouteMiddleware($route);

    expect($middleware)
        ->not->toContain(EnsureFrontendRequestsAreStateful::class)
        ->not->toContain(RestoreSessionFromRememberToken::class)
        ->not->toContain(SetLocaleFromHeader::class)
        ->not->toContain(InjectTenantId::class)
        ->toContain(ForceJsonResponse::class)
        ->toContain(ThrottleRequests::class.':'.$throttleName);
})->with([
    'bootstrap' => ['/v1/bootstrap', 'bootstrap'],
    'release' => ['/v1/release', 'release'],
    'source' => ['/v1/source', 'source-offer'],
]);

test('spa login and protected routes retain stateful middleware', function (string $path, string $method): void {
    /** @var Router $router */
    $router = app('router');
    $route = $router->getRoutes()->match(Request::create($path, $method));

    expect($router->gatherRouteMiddleware($route))
        ->toContain(EnsureFrontendRequestsAreStateful::class)
        ->toContain(RestoreSessionFromRememberToken::class);
})->with([
    'spa login' => ['/v1/auth/login', 'POST'],
    'protected me' => ['/v1/me', 'GET'],
]);
