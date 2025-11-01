<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Controllers\Api\PersonController;
use App\Http\Middleware\SetTenant;
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
    // Protected tenant-scoped routes
    Route::middleware(['auth:sanctum', SetTenant::class])->group(function () {
        // Person API endpoints (specific routes BEFORE parameterized routes)
        Route::prefix('tenants/{tenant}')->where(['tenant' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])->group(function () {
            Route::get('/persons/by-email', [PersonController::class, 'findByEmail']);
            Route::get('/persons', [PersonController::class, 'index']);
            Route::post('/persons', [PersonController::class, 'store']);
            Route::get('/persons/{id}', [PersonController::class, 'show'])->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::put('/persons/{id}', [PersonController::class, 'update'])->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            Route::delete('/persons/{id}', [PersonController::class, 'destroy'])->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
        });
    });
});
