<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Middleware\SetTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

describe('TenantMiddleware', function () {
    beforeEach(function () {
        // Register test route with SetTenant middleware
        Route::middleware([SetTenant::class])->get('/test-tenant/{tenant}', function (Request $request) {
            return response()->json([
                'tenant_id' => $request->tenant_id,
                'permissions_team_id' => getPermissionsTeamId(),
            ]);
        });
    });

    test('it validates tenant_id is a valid UUID', function () {
        $response = $this->getJson('/test-tenant/invalid-uuid');

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Invalid tenant_id format',
        ]);
    });

    test('it accepts valid tenant_id and sets request attribute', function () {
        $tenantId = '123e4567-e89b-12d3-a456-426614174000';

        $response = $this->getJson("/test-tenant/{$tenantId}");

        $response->assertStatus(200);
        $response->assertJson([
            'tenant_id' => $tenantId,
        ]);
    });

    test('it sets Spatie Permission team context', function () {
        $tenantId = '123e4567-e89b-12d3-a456-426614174000';

        $response = $this->getJson("/test-tenant/{$tenantId}");

        $response->assertStatus(200);
        $response->assertJson([
            'permissions_team_id' => $tenantId,
        ]);
    });

    test('it rejects missing tenant_id parameter', function () {
        Route::middleware([SetTenant::class])->get('/test-no-tenant', function () {
            return response()->json(['ok' => true]);
        });

        $response = $this->getJson('/test-no-tenant');

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'tenant_id is required',
        ]);
    });

    test('it normalizes UUID format (lowercase, no braces)', function () {
        $tenantId = '123E4567-E89B-12D3-A456-426614174000'; // Uppercase
        $expected = '123e4567-e89b-12d3-a456-426614174000';

        $response = $this->getJson("/test-tenant/{$tenantId}");

        $response->assertStatus(200);
        $response->assertJson([
            'tenant_id' => $expected,
        ]);
    });
});
