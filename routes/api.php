<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Controllers\Api\V1\PermissionManagementController;
use App\Http\Controllers\Api\V1\RoleManagementController;
use App\Http\Controllers\Api\V1\UserPermissionController;
use App\Http\Controllers\AuthController;
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
    Route::post('/auth/token', [AuthController::class, 'token']);
    Route::post('/auth/password/reset-request', [AuthController::class, 'passwordResetRequest'])
        ->middleware('throttle:password-reset');
    Route::post('/auth/password/reset', [AuthController::class, 'passwordReset'])
        ->middleware('throttle:password-reset');

    // Protected routes (require auth:sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/me', [AuthController::class, 'me']);

        // Role Management CRUD API
        // Authorization handled by RoleManagementPolicy
        Route::get('/roles', [RoleManagementController::class, 'index']);
        Route::post('/roles', [RoleManagementController::class, 'store']);
        Route::get('/roles/{id}', [RoleManagementController::class, 'show']);
        Route::patch('/roles/{id}', [RoleManagementController::class, 'update']);
        Route::delete('/roles/{id}', [RoleManagementController::class, 'destroy']);

        // Permission Management CRUD API
        // Authorization handled by PermissionManagementPolicy
        Route::get('/permissions', [PermissionManagementController::class, 'index']);
        Route::post('/permissions', [PermissionManagementController::class, 'store']);
        Route::get('/permissions/{id}', [PermissionManagementController::class, 'show']);
        Route::patch('/permissions/{id}', [PermissionManagementController::class, 'update']);
        Route::delete('/permissions/{id}', [PermissionManagementController::class, 'destroy']);

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
        // Authorization handled by UserPermissionPolicy (viewPermissions, assignPermission, revokePermission)
        Route::get('/users/{user}/permissions', [UserPermissionController::class, 'index']);
        Route::post('/users/{user}/permissions', [UserPermissionController::class, 'store']);
        Route::delete('/users/{user}/permissions/{permission}', [UserPermissionController::class, 'destroy']);
        Route::get('/users/{user}/permissions/direct', [UserPermissionController::class, 'direct']);

        // Tenant-scoped Person endpoints
        Route::prefix('tenants/{tenant}')->middleware('tenant')->group(function () {
            Route::post('/persons', [PersonController::class, 'store'])
                ->middleware('permission:person.write');
            Route::get('/persons/by-email', [PersonController::class, 'byEmail'])
                ->middleware('permission:person.read');
        });
    });
});
