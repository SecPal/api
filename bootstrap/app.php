<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '', // Remove /api/ prefix - routes accessible at /v1/* directly
        commands: __DIR__.'/../routes/console.php',
        then: static function (): void {
            require base_path('routes/health.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => App\Http\Middleware\SetTenant::class,
            'tenant.inject' => App\Http\Middleware\InjectTenantId::class,
            'check.organizational.scope' => App\Http\Middleware\CheckOrganizationalScope::class,
            'health.throttle' => App\Http\Middleware\HealthThrottle::class,
            'abilities' => Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'permission' => Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => Spatie\Permission\Middleware\RoleMiddleware::class,
            'ensure.pre_contract' => App\Http\Middleware\EnsurePreContract::class,
            'ensure.not_pre_contract' => App\Http\Middleware\EnsureNotPreContract::class,
        ]);

        // Apply security headers globally to all requests (including API routes and Sanctum routes like /sanctum/csrf-cookie)
        $middleware->append(App\Http\Middleware\SecurityHeaders::class);

        // Locale must run globally so unmatched routes (404) still honor Accept-Language for JSON error payloads.
        $middleware->append(App\Http\Middleware\SetLocaleFromHeader::class);

        // Apply Sanctum's stateful middleware to API routes for SPA authentication
        // This enables session-based auth for requests from stateful SPA domains (such as app.secpal.dev).
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
            // Run locale resolution again after Sanctum's stateful pipeline so authenticated users can override Accept-Language.
            App\Http\Middleware\SetLocaleFromHeader::class,
            App\Http\Middleware\InjectTenantId::class,
        ]);

        // Configure CSRF protection
        // Token endpoint is excluded since it's for mobile/native apps without CSRF cookies
        // Login endpoint requires CSRF token (fetched via /sanctum/csrf-cookie first)
        $middleware->validateCsrfTokens(except: [
            'v1/auth/token',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $shouldRenderApiJson = static function (Request $request): bool {
            return $request->is('v1', 'v1/*') || $request->expectsJson();
        };

        // Return JSON 401 response for unauthenticated API requests
        // Prevents "Route [login] not defined" error since this is a pure API without web routes
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json(['message' => __('Unauthenticated.')], 401);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => __('Resource not found.'),
            ], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => __('Resource not found.'),
            ], 404);
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            if ($e instanceof ValidationException
                || $e instanceof AuthorizationException
                || $e instanceof HttpResponseException) {
                return null;
            }

            $status = method_exists($e, 'getStatusCode')
                ? $e->getStatusCode()
                : 500;

            return response()->json([
                'message' => $status >= 500
                    ? __('Internal server error.')
                    : ($e->getMessage() !== '' ? $e->getMessage() : __('Request failed.')),
            ], $status);
        });
    })->create();
