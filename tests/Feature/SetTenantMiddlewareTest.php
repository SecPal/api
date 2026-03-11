<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Generate KEK if it doesn't exist
    if (! file_exists(storage_path('app/keys/kek.key'))) {
        TenantKey::generateKek();
    }

    // Register test routes that use tenant middleware
    Route::middleware(['tenant'])->group(function (): void {
        Route::get('/tenants/{tenant}/test', function (): array {
            return ['success' => true, 'tenant_id' => request('tenant_id')];
        })->name('test.tenant');

        Route::get('/api/test', function (): array {
            return ['success' => true, 'tenant_id' => request('tenant_id')];
        })->name('test.header');
    });
});

describe('SetTenant Middleware', function (): void {
    it('returns 400 when tenant ID is missing', function (): void {
        $response = $this->getJson('/api/test');

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Tenant ID is required. Please provide tenant ID in path (/tenants/{tenant}) or X-Tenant header.',
            ]);
    });

    it('returns 404 when tenant does not exist', function (): void {
        $response = $this->getJson('/tenants/999/test');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Tenant not found. The specified tenant does not exist.',
            ]);
    });

    it('accepts tenant ID from path parameter', function (): void {
        // Create tenant with envelope keys
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $response = $this->getJson("/tenants/{$tenant->id}/test");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'tenant_id' => $tenant->id,
            ]);
    });

    it('accepts tenant ID from X-Tenant header', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $response = $this->getJson('/api/test', [
            'X-Tenant' => (string) $tenant->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'tenant_id' => $tenant->id,
            ]);
    });

    it('prioritizes path parameter over X-Tenant header', function (): void {
        $keys1 = TenantKey::generateEnvelopeKeys();
        $tenant1 = TenantKey::create($keys1);

        $keys2 = TenantKey::generateEnvelopeKeys();
        $tenant2 = TenantKey::create($keys2);

        $response = $this->getJson("/tenants/{$tenant1->id}/test", [
            'X-Tenant' => (string) $tenant2->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'tenant_id' => $tenant1->id, // Should use path param, not header
            ]);
    });

    it('sets Spatie Permission team ID correctly', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $this->getJson("/tenants/{$tenant->id}/test");

        // Verify that PermissionRegistrar has the correct team ID set
        $registrar = app(Spatie\Permission\PermissionRegistrar::class);
        expect($registrar->getPermissionsTeamId())->toBe($tenant->id);
    });

    it('prevents cross-tenant access', function (): void {
        $keys1 = TenantKey::generateEnvelopeKeys();
        $tenant1 = TenantKey::create($keys1);

        $keys2 = TenantKey::generateEnvelopeKeys();
        $tenant2 = TenantKey::create($keys2);

        // First request sets tenant1
        $this->getJson("/tenants/{$tenant1->id}/test");
        $registrar = app(Spatie\Permission\PermissionRegistrar::class);
        expect($registrar->getPermissionsTeamId())->toBe($tenant1->id);

        // Second request should change to tenant2
        $this->getJson("/tenants/{$tenant2->id}/test");
        expect($registrar->getPermissionsTeamId())->toBe($tenant2->id);

        // Verify it's not still set to tenant1
        expect($registrar->getPermissionsTeamId())->not->toBe($tenant1->id);
    });

    it('handles invalid tenant ID gracefully', function (): void {
        $response = $this->getJson('/tenants/invalid/test');

        // Should treat 'invalid' as 0 and return 404
        $response->assertStatus(404);
    });

    it('stores tenant_id in request for controller access', function (): void {
        $keys = TenantKey::generateEnvelopeKeys();
        $tenant = TenantKey::create($keys);

        $response = $this->getJson("/tenants/{$tenant->id}/test");

        // The test route returns the tenant_id from the request
        $response->assertStatus(200)
            ->assertJson([
                'tenant_id' => $tenant->id,
            ]);
    });
});
