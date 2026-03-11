<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Middleware\InjectTenantId;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;

/**
 * @property TenantKey $tenant
 * @property User $user
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create tenant
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Create authenticated user in this tenant
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

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
    test('injects tenant_id from authenticated user', function () {
        $response = actingAs($this->user, 'sanctum')
            ->postJson('/test/inject-tenant');

        $response->assertOk()
            ->assertJson([
                'tenant_id' => $this->user->tenant_id,
            ]);
    });

    test('returns 401 when user is not authenticated', function () {
        $response = $this->postJson('/test/inject-tenant');

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    });

    test('middleware removes client-provided tenant_id (security fix)', function () {
        // Attempt to spoof tenant_id - middleware should ignore it
        $maliciousTenantId = $this->user->tenant_id + 9999; // Ensure it's different
        $response = actingAs($this->user, 'sanctum')
            ->postJson('/test/inject-tenant', ['tenant_id' => $maliciousTenantId]);

        $response->assertOk();
        // Should use user's tenant_id, NOT client-provided value
        expect($response->json('tenant_id'))->toBe($this->user->tenant_id);
        expect($response->json('tenant_id'))->not->toBe($maliciousTenantId);
    });

    test('middleware removes tenant_id from query string (security fix)', function () {
        // Attempt to spoof tenant_id via query string
        $maliciousTenantId = $this->user->tenant_id + 9999; // Ensure it's different
        $response = actingAs($this->user, 'sanctum')
            ->postJson("/test/inject-tenant?tenant_id={$maliciousTenantId}");

        $response->assertOk();
        // Should use user's tenant_id, NOT query string value
        expect($response->json('tenant_id'))->toBe($this->user->tenant_id);
        expect($response->json('tenant_id'))->not->toBe($maliciousTenantId);
    });

    test('multiple users from different tenants get their own tenant_id', function () {
        // Create second tenant
        $keys2 = TenantKey::generateEnvelopeKeys();
        $tenant2 = TenantKey::create($keys2);

        // Create user in second tenant
        $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

        // First user gets tenant 1
        $response1 = actingAs($this->user, 'sanctum')
            ->postJson('/test/inject-tenant');

        $response1->assertOk();
        expect($response1->json('tenant_id'))->toBe($this->tenant->id);

        // Second user gets tenant 2
        $response2 = actingAs($user2, 'sanctum')
            ->postJson('/test/inject-tenant');

        $response2->assertOk();
        expect($response2->json('tenant_id'))->toBe($tenant2->id);

        // Verify tenant isolation
        expect($response1->json('tenant_id'))->not->toBe($response2->json('tenant_id'));
    });

    test('middleware sets Spatie Permission team ID correctly', function () {
        $middleware = new InjectTenantId;

        $request = Request::create('/test', 'POST');
        $request->setUserResolver(fn () => $this->user);

        $middleware->handle($request, function ($req) {
            // Verify that Spatie Permission team ID was set
            $permissionRegistrar = app(Spatie\Permission\PermissionRegistrar::class);
            expect($permissionRegistrar->getPermissionsTeamId())->toBe($this->user->tenant_id);

            return response()->json(['ok' => true]);
        });
    });

    test('handles user without tenant_id gracefully', function () {
        // This scenario should never happen due to NOT NULL constraint
        // but we test defensive programming

        // Create request with mock user that has null tenant_id
        $middleware = new InjectTenantId;
        $request = Request::create('/test', 'POST');

        $mockUser = new class
        {
            public $tenant_id = null;
        };

        $request->setUserResolver(fn () => $mockUser);

        $response = $middleware->handle($request, function ($req) {
            return response()->json(['ok' => true]);
        });

        expect($response->getStatusCode())->toBe(500);
        $content = json_decode($response->getContent(), true);
        expect($content['message'])->toContain('no assigned tenant');
    });

    test('middleware removes tenant_id from request body and query', function () {
        // Test that middleware explicitly removes tenant_id from both sources
        $middleware = new InjectTenantId;
        // Use UUIDs that will never match auto-increment IDs
        $fakeBodyTenantId = 'aaaaaaaa-bbbb-cccc-dddd-111111111111';
        $fakeQueryTenantId = 'aaaaaaaa-bbbb-cccc-dddd-999999999999';
        $request = Request::create("/test?tenant_id={$fakeQueryTenantId}", 'POST', ['tenant_id' => $fakeBodyTenantId]);
        $request->setUserResolver(fn () => $this->user);

        $middleware->handle($request, function ($req) use ($fakeBodyTenantId, $fakeQueryTenantId) {
            // Verify middleware injected correct tenant_id
            expect($req->input('tenant_id'))->toBe($this->user->tenant_id);
            // Verify client-provided values were removed
            expect($req->input('tenant_id'))->not->toBe($fakeBodyTenantId);
            expect($req->input('tenant_id'))->not->toBe($fakeQueryTenantId);

            return response()->json(['ok' => true]);
        });
    });
});
