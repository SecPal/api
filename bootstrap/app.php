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
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\SetTenant::class,
            'tenant.inject' => \App\Http\Middleware\InjectTenantId::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        ]);

        // Apply security headers globally to all requests (including API routes and Sanctum routes like /sanctum/csrf-cookie)
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Apply middleware to all API routes
        $middleware->api(append: [
            \App\Http\Middleware\SetLocaleFromHeader::class,
        ]);

        // Configure CORS for SPA authentication with credentials
        $middleware->validateCsrfTokens(except: [
            // CSRF protection is active - Sanctum middleware handles validation for authenticated SPA routes
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON 401 response for unauthenticated API requests
        // Prevents "Route [login] not defined" error since this is a pure API without web routes
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })->create();
