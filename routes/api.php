<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\GuardBookController;
use App\Http\Controllers\Api\V1\GuardBookReportController;
use App\Http\Controllers\Api\V1\ObjectAreaController;
use App\Http\Controllers\Api\V1\OrganizationalScopeController;
use App\Http\Controllers\Api\V1\OrganizationalUnitController;
use App\Http\Controllers\Api\V1\PermissionManagementController;
use App\Http\Controllers\Api\V1\RoleManagementController;
use App\Http\Controllers\Api\V1\SecPalObjectController;
use App\Http\Controllers\Api\V1\SecretAttachmentController;
use App\Http\Controllers\Api\V1\SecretController;
use App\Http\Controllers\Api\V1\SecretShareController;
use App\Http\Controllers\Api\V1\UserPermissionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'service' => 'SecPal API',
        'version' => config('app.version', '1.0.0'),
    ]);
});

// API v1 routes
Route::prefix('v1')->group(function () {
    // Authentication routes (public)
    // SPA Login (session-based, for web browsers)
    // EnsureFrontendRequestsAreStateful middleware handles session/cookie middleware automatically
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
    // Token Login (for mobile/native apps)
    Route::post('/auth/token', [AuthController::class, 'token'])
        ->middleware('throttle:login');
    Route::post('/auth/password/reset-request', [AuthController::class, 'passwordResetRequest'])
        ->middleware('throttle:password-reset');
    Route::post('/auth/password/reset', [AuthController::class, 'passwordReset'])
        ->middleware('throttle:password-reset');

    // Protected routes (require auth:sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        // Token logout (for Bearer token auth - mobile/native apps)
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        // Session logout (for SPA cookie auth)
        Route::post('/auth/session/logout', [AuthController::class, 'logoutSession']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me/language', [AuthController::class, 'updateLanguage']);

        // Role Management CRUD API
        // Authorization: Route-level permission middleware + Policy (defense-in-depth)
        Route::get('/roles', [RoleManagementController::class, 'index'])
            ->middleware('permission:roles.read');
        Route::post('/roles', [RoleManagementController::class, 'store'])
            ->middleware('permission:roles.create');
        Route::get('/roles/{id}', [RoleManagementController::class, 'show'])
            ->middleware('permission:roles.read');
        Route::patch('/roles/{id}', [RoleManagementController::class, 'update'])
            ->middleware('permission:roles.update');
        Route::delete('/roles/{id}', [RoleManagementController::class, 'destroy'])
            ->middleware('permission:roles.delete');

        // Permission Management CRUD API
        // Authorization: Route-level permission middleware + Policy (defense-in-depth)
        Route::get('/permissions', [PermissionManagementController::class, 'index'])
            ->middleware('permission:permissions.read');
        Route::post('/permissions', [PermissionManagementController::class, 'store'])
            ->middleware('permission:permissions.create');
        Route::get('/permissions/{id}', [PermissionManagementController::class, 'show'])
            ->middleware('permission:permissions.read');
        Route::patch('/permissions/{id}', [PermissionManagementController::class, 'update'])
            ->middleware('permission:permissions.update');
        Route::delete('/permissions/{id}', [PermissionManagementController::class, 'destroy'])
            ->middleware('permission:permissions.delete');

        // Role management endpoints
        Route::post('/users/{user}/roles', [RoleController::class, 'store'])
            ->middleware('permission:role.assign');
        Route::get('/users/{user}/roles', [RoleController::class, 'index'])
            ->middleware('permission:role.read');
        Route::delete('/users/{user}/roles/{role}', [RoleController::class, 'destroy'])
            ->middleware('permission:role.revoke');
        Route::patch('/users/{user}/roles/{role}/extend', [RoleController::class, 'extend'])
            ->middleware('permission:role.assign');

        // User Direct Permission Assignment API (RBAC Phase 4)
        // Authorization: Policy-based (users can view own, Admin can view all/modify)
        Route::get('/users/{user}/permissions', [UserPermissionController::class, 'index']);
        // Authorization: Route-level permission middleware + Policy (Admin only)
        Route::post('/users/{user}/permissions', [UserPermissionController::class, 'store'])
            ->middleware('permission:permissions.assign_direct');
        Route::delete('/users/{user}/permissions/{permission}', [UserPermissionController::class, 'destroy'])
            ->middleware('permission:permissions.revoke_direct');
        // Authorization: Policy-based (users can view own, Admin can view all)
        Route::get('/users/{user}/permissions/direct', [UserPermissionController::class, 'direct']);

        // Tenant-scoped Person endpoints
        Route::prefix('tenants/{tenant}')->middleware('tenant')->group(function () {
            Route::post('/persons', [PersonController::class, 'store'])
                ->middleware('permission:person.write');
            Route::get('/persons/by-email', [PersonController::class, 'byEmail'])
                ->middleware('permission:person.read');
        });

        // Secret Attachment endpoints (File Attachments API - Phase 2)
        Route::middleware('tenant.inject')->group(function () {
            Route::post('/secrets/{secret}/attachments', [SecretAttachmentController::class, 'store']);
            Route::get('/secrets/{secret}/attachments', [SecretAttachmentController::class, 'index']);
            Route::get('/attachments/{attachment}/download', [SecretAttachmentController::class, 'download']);
            Route::delete('/attachments/{attachment}', [SecretAttachmentController::class, 'destroy']);
        });

        // Secret Sharing endpoints (Phase 3) - must be before single {secret} routes
        Route::middleware('tenant.inject')->group(function () {
            Route::get('/secrets/{secret}/shares', [SecretShareController::class, 'index']);
            Route::post('/secrets/{secret}/shares', [SecretShareController::class, 'store']);
            Route::delete('/secrets/{secret}/shares/{share}', [SecretShareController::class, 'destroy']);
        });

        // Secret CRUD endpoints (Phase 3)
        Route::middleware('tenant.inject')->group(function () {
            Route::get('/secrets', [SecretController::class, 'index']);
            Route::post('/secrets', [SecretController::class, 'store']);
            Route::get('/secrets/{secret}', [SecretController::class, 'show']);
            Route::patch('/secrets/{secret}', [SecretController::class, 'update']);
            Route::delete('/secrets/{secret}', [SecretController::class, 'destroy']);
        });

        // Organizational Unit Scope Management (RBAC Issue #234)
        // Defense-in-depth: Middleware pre-checks admin access, controller uses policy for authorization
        Route::get('/me/organizational-scopes', [OrganizationalScopeController::class, 'myScopes']);
        Route::prefix('organizational-units/{organizational_unit}')
            ->middleware('check.organizational.scope:admin')
            ->group(function () {
                Route::get('/scopes', [OrganizationalScopeController::class, 'index']);
                Route::post('/scopes', [OrganizationalScopeController::class, 'store']);
                Route::patch('/scopes/{scope}', [OrganizationalScopeController::class, 'update']);
                Route::delete('/scopes/{scope}', [OrganizationalScopeController::class, 'destroy']);
            });

        // ==========================================================================
        // Organizational Structure & Customer Hierarchies REST API (Issue #236)
        // All routes use tenant.inject middleware for automatic tenant_id injection
        // Authorization is handled by respective Policies (defense-in-depth)
        // ==========================================================================

        // Organizational Units (internal company structure)
        Route::middleware('tenant.inject')->group(function () {
            Route::get('/organizational-units', [OrganizationalUnitController::class, 'index']);
            Route::post('/organizational-units', [OrganizationalUnitController::class, 'store']);
            Route::get('/organizational-units/{organizational_unit}', [OrganizationalUnitController::class, 'show']);
            Route::patch('/organizational-units/{organizational_unit}', [OrganizationalUnitController::class, 'update']);
            Route::delete('/organizational-units/{organizational_unit}', [OrganizationalUnitController::class, 'destroy']);
            // Hierarchy management
            Route::get('/organizational-units/{organizational_unit}/descendants', [OrganizationalUnitController::class, 'descendants']);
            Route::get('/organizational-units/{organizational_unit}/ancestors', [OrganizationalUnitController::class, 'ancestors']);
            Route::post('/organizational-units/{organizational_unit}/parent', [OrganizationalUnitController::class, 'attachParent']);
            Route::delete('/organizational-units/{organizational_unit}/parent/{parent}', [OrganizationalUnitController::class, 'detachParent']);
        });

        // Customers (external customer hierarchies)
        Route::middleware('tenant.inject')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
            Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
            // Hierarchy management
            Route::get('/customers/{customer}/descendants', [CustomerController::class, 'descendants']);
            Route::get('/customers/{customer}/ancestors', [CustomerController::class, 'ancestors']);
            Route::post('/customers/{customer}/parent', [CustomerController::class, 'attachParent']);
            Route::delete('/customers/{customer}/parent/{parent}', [CustomerController::class, 'detachParent']);
        });

        // Objects (physical locations)
        Route::middleware('tenant.inject')->group(function () {
            Route::get('/objects', [SecPalObjectController::class, 'index']);
            Route::post('/objects', [SecPalObjectController::class, 'store']);
            Route::get('/objects/{object}', [SecPalObjectController::class, 'show']);
            Route::patch('/objects/{object}', [SecPalObjectController::class, 'update']);
            Route::delete('/objects/{object}', [SecPalObjectController::class, 'destroy']);
            // Nested areas
            Route::get('/objects/{object}/areas', [SecPalObjectController::class, 'areas']);
            Route::post('/objects/{object}/areas', [SecPalObjectController::class, 'storeArea']);
        });

        // Object Areas (sub-divisions of objects)
        Route::middleware('tenant.inject')->group(function () {
            Route::get('/object-areas', [ObjectAreaController::class, 'index']);
            Route::get('/object-areas/{object_area}', [ObjectAreaController::class, 'show']);
            Route::patch('/object-areas/{object_area}', [ObjectAreaController::class, 'update']);
            Route::delete('/object-areas/{object_area}', [ObjectAreaController::class, 'destroy']);
        });

        // Guard Books (continuous event stream containers)
        Route::middleware('tenant.inject')->group(function () {
            Route::get('/guard-books', [GuardBookController::class, 'index']);
            Route::post('/guard-books', [GuardBookController::class, 'store']);
            Route::get('/guard-books/{guard_book}', [GuardBookController::class, 'show']);
            Route::patch('/guard-books/{guard_book}', [GuardBookController::class, 'update']);
            Route::delete('/guard-books/{guard_book}', [GuardBookController::class, 'destroy']);
            // Reports
            Route::get('/guard-books/{guard_book}/reports', [GuardBookController::class, 'reports']);
            Route::post('/guard-books/{guard_book}/reports', [GuardBookController::class, 'generateReport']);
        });

        // Guard Book Reports (snapshots of events)
        Route::middleware('tenant.inject')->group(function () {
            Route::get('/guard-book-reports', [GuardBookReportController::class, 'index']);
            Route::get('/guard-book-reports/{guard_book_report}', [GuardBookReportController::class, 'show']);
            Route::get('/guard-book-reports/{guard_book_report}/export', [GuardBookReportController::class, 'export']);
            Route::delete('/guard-book-reports/{guard_book_report}', [GuardBookReportController::class, 'destroy']);
        });
    });
});
