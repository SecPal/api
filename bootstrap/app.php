<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '', // Remove /api/ prefix - routes accessible at /v1/* directly
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => App\Http\Middleware\SetTenant::class,
            'tenant.inject' => App\Http\Middleware\InjectTenantId::class,
            'check.organizational.scope' => App\Http\Middleware\CheckOrganizationalScope::class,
            'check.customer.scope' => App\Http\Middleware\CheckCustomerScope::class,
            'permission' => Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => Spatie\Permission\Middleware\RoleMiddleware::class,
            'ensure.pre_contract' => App\Http\Middleware\EnsurePreContract::class,
            'ensure.not_pre_contract' => App\Http\Middleware\EnsureNotPreContract::class,
        ]);

        // Apply security headers globally to all requests (including API routes and Sanctum routes like /sanctum/csrf-cookie)
        $middleware->append(App\Http\Middleware\SecurityHeaders::class);

        // Apply Sanctum's stateful middleware to API routes for SPA authentication
        // This enables session-based auth for requests from stateful SPA domains such as app.secpal.dev.
        // RestoreSessionFromRememberToken must run AFTER EnsureFrontendRequestsAreStateful
        // to restore sessions from remember tokens when session expires but remember cookie is valid
        // ForceJsonResponse ensures all API routes return JSON, never HTML (prevents HTML error pages on validation errors)
        $middleware->api(prepend: [
            App\Http\Middleware\ForceJsonResponse::class,
            Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            App\Http\Middleware\RestoreSessionFromRememberToken::class,
        ]);

        // Apply middleware to all API routes
        $middleware->api(append: [
            App\Http\Middleware\InjectTenantId::class,
            App\Http\Middleware\SetLocaleFromHeader::class,
        ]);

        // Configure CSRF protection
        // Token endpoint is excluded since it's for mobile/native apps without CSRF cookies
        // Login endpoint requires CSRF token (fetched via /sanctum/csrf-cookie first)
        $middleware->validateCsrfTokens(except: [
            'v1/auth/token',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON 401 response for unauthenticated API requests
        // Prevents "Route [login] not defined" error since this is a pure API without web routes
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })->create();
