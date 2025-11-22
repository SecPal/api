<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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

        // Apply SetLocaleFromHeader middleware to all API routes
        $middleware->api(append: [
            \App\Http\Middleware\SetLocaleFromHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
