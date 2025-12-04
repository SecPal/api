<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Middleware\InjectTenantId;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create tenant
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Create authenticated user
    $this->user = User::factory()->create();

    // Register test route with middleware
    Route::middleware(['auth:sanctum', InjectTenantId::class])->group(function () {
        Route::post('/test/inject-tenant', function (Request $request) {
            return response()->json([
                'tenant_id' => $request->input('tenant_id'),
            ]);
        });
    });
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('InjectTenantId Middleware', function () {
    test('injects tenant_id from first available TenantKey', function () {
        $response = actingAs($this->user, 'sanctum')
            ->postJson('/test/inject-tenant');

        $response->assertOk()
            ->assertJson([
                'tenant_id' => $this->tenant->id,
            ]);
    });

    test('returns 503 when no TenantKey exists', function () {
        // Delete the tenant
        $this->tenant->delete();

        $response = actingAs($this->user, 'sanctum')
            ->postJson('/test/inject-tenant');

        $response->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
            ->assertJson([
                'message' => 'No tenant keys available. Please ensure at least one tenant key is configured.',
            ]);
    });

    test('middleware does not overwrite existing tenant_id in request', function () {
        // Test that middleware respects pre-existing tenant_id
        // by directly calling the middleware handle method
        $middleware = new InjectTenantId;

        $request = Request::create('/test', 'POST');
        $request->merge(['tenant_id' => 999]); // Pre-set tenant_id
        $request->setUserResolver(fn () => $this->user);

        $response = $middleware->handle($request, function ($req) {
            return response()->json([
                'tenant_id' => $req->input('tenant_id'),
            ]);
        });

        expect($response->getStatusCode())->toBe(200);
        expect(json_decode($response->getContent(), true)['tenant_id'])->toBe(999);
    });

    test('injects tenant_id when user is authenticated', function () {
        $response = actingAs($this->user, 'sanctum')
            ->postJson('/test/inject-tenant', ['foo' => 'bar']);

        $response->assertOk();
        expect($response->json('tenant_id'))->toBe($this->tenant->id);
    });

    test('middleware works with multiple TenantKeys (uses first)', function () {
        // Create a second tenant
        $keys2 = TenantKey::generateEnvelopeKeys();
        $tenant2 = TenantKey::create($keys2);

        $response = actingAs($this->user, 'sanctum')
            ->postJson('/test/inject-tenant');

        $response->assertOk();

        // Should use first tenant (oldest by ID)
        $firstTenantId = TenantKey::oldest('id')->value('id');
        expect($response->json('tenant_id'))->toBe($firstTenantId);
    });
});
