<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Exceptions\CustomerDomainDependencyConflictException;
use App\Exceptions\DuplicateResourceException;
use App\SecurityEvents\SecurityEventName;
use App\Services\SecurityEventRecorder;
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
        $middleware->trimStrings(except: [
            static fn (Request $request): bool => $request->is('v1/internal-cost-centers')
                && $request->isMethod('post'),
            static fn (Request $request): bool => ($request->is('v1/work-instructions')
                && $request->isMethod('post'))
                || ($request->is('v1/work-instructions/*') && $request->isMethod('patch')),
            static fn (Request $request): bool => $request->is('v1/work-instruction-templates', 'v1/work-instruction-templates/*')
                && in_array($request->method(), ['POST', 'PUT'], true),
        ]);

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

            if ($request->bearerToken() !== null) {
                app(SecurityEventRecorder::class)->record(
                    $request,
                    SecurityEventName::AuthenticationTokenRejected,
                    ['authentication_method' => 'bearer_token'],
                );
            }

            return response()->json(['message' => __('Unauthenticated.')], 401);
        });

        $exceptions->render(function (App\Exceptions\LegalHoldTargetNotFoundException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Resource not found',
                'code' => 'NOT_FOUND',
            ], 404);
        });

        $exceptions->render(function (App\Exceptions\ContractTargetNotFoundException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Resource not found',
                'code' => 'NOT_FOUND',
            ], 404);
        });

        $exceptions->render(function (App\Exceptions\ServiceBookingTargetNotFoundException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Resource not found',
                'code' => 'NOT_FOUND',
            ], 404);
        });

        $exceptions->render(function (App\Exceptions\InternalCostCenterTargetNotFoundException|App\Exceptions\CostCenterAllocationTargetNotFoundException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Resource not found',
                'code' => 'NOT_FOUND',
            ], 404);
        });

        $exceptions->render(function (App\Exceptions\WorkInstructionTargetNotFoundException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Resource not found',
                'code' => 'NOT_FOUND',
            ], 404);
        });

        $exceptions->render(function (App\Exceptions\WorkInstructionContentTargetNotFoundException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Resource not found',
                'code' => 'NOT_FOUND',
            ], 404);
        });

        $exceptions->render(function (App\Exceptions\WorkInstructionContentIntegrityException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Internal server error',
                'code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        });

        $exceptions->render(function (App\Exceptions\WorkInstructionConflictException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\InternalCostCenterConflictException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\CostCenterAllocationInactiveTargetException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'An allocation target is inactive.',
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\CostCenterAllocationConflictException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'The allocation could not be replaced because authoritative state changed concurrently.',
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\ServiceBookingRetiredException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'The Service Booking is retired.',
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\ServiceBookingInvoicedException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'The Service Booking is invoiced.',
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\ServiceBookingConcurrentTransitionException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'The Service Booking changed in a concurrent state transition.',
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\ContractRetiredException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'The Contract is retired.',
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\ContractCustomerHistoryConflictException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'The Contract customer cannot change after service booking history exists.',
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\ContractCurrencyHistoryConflictException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'The Contract currency cannot change after service booking history exists.',
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\LegalHoldCaseReferenceConflictException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'A Legal Hold with this case reference already exists.',
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\LegalHoldNotActiveException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'The Legal Hold is not active.',
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\DuplicateActiveLegalHoldAttachmentException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'The Activity is already actively attached to this Legal Hold.',
                'code' => 'CONFLICT',
            ], 409);
        });

        $exceptions->render(function (App\Exceptions\LegalHoldAttachmentAlreadyDetachedException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'The attachment is already detached.',
                'code' => 'CONFLICT',
            ], 409);
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

        $exceptions->render(function (DuplicateResourceException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => __('A matching record already exists.'),
                'code' => 'DUPLICATE_RESOURCE',
            ], 409);
        });

        $exceptions->render(function (CustomerDomainDependencyConflictException $e, Request $request) use ($shouldRenderApiJson) {
            if (! $shouldRenderApiJson($request)) {
                return null;
            }

            return response()->json([
                'message' => __('The request cannot be completed in the current resource state.'),
                'code' => 'CONFLICT',
            ], 409);
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

            if ($status >= 500 && $request->is('v1/legal-holds', 'v1/legal-holds/*')) {
                return response()->json([
                    'message' => 'An internal error occurred',
                    'code' => 'INTERNAL_ERROR',
                ], $status);
            }

            if ($status >= 500 && $request->is(
                'v1/work-instruction-templates',
                'v1/work-instruction-templates/*',
                'v1/standard-blocks',
                'v1/standard-blocks/*',
            )) {
                return response()->json([
                    'message' => 'Internal server error',
                    'code' => 'INTERNAL_SERVER_ERROR',
                ], $status);
            }

            return response()->json([
                'message' => $status >= 500
                    ? __('Internal server error.')
                    : ($e->getMessage() !== '' ? $e->getMessage() : __('Request failed.')),
            ], $status);
        });
    })->create();
