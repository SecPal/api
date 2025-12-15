<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;
});

afterEach(function (): void {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/me/customer-assignments', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/me/customer-assignments');
        $response->assertStatus(401);
    });

    test('returns authenticated users customer assignments', function (): void {
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Create 2 assignments for authenticated user with explicit roles
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'role' => 'Account Manager',
        ]);
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'role' => 'Billing Contact',
        ]);

        // Create 1 assignment for another user (should NOT appear)
        $otherUser = User::factory()->create();
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/customer-assignments');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'role', 'is_active', 'user', 'customer'],
                ],
            ]);

        expect($response->json('data'))->toHaveCount(2);
        expect($response->json('data')[0]['user']['id'])->toBe($this->user->id);
    });

    test('includes eager-loaded customer relationship', function (): void {
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Acme Corp',
        ]);

        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/customer-assignments');

        $response->assertOk();
        expect($response->json('data')[0]['customer']['name'])->toBe('Acme Corp');
    });

    test('filters by active_only parameter', function (): void {
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Active assignment
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'valid_from' => null,
            'valid_until' => null,
        ]);

        // Expired assignment
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'valid_until' => now()->subDay(),
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/customer-assignments?active_only=1');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['is_active'])->toBeTrue();
    });

    test('returns empty array when user has no assignments', function (): void {
        $response = $this->withToken($this->token)
            ->getJson('/v1/me/customer-assignments');

        $response->assertOk();
        expect($response->json('data'))->toBeArray();
        expect($response->json('data'))->toHaveCount(0);
    });

    test('returns only assignments for current tenant', function (): void {
        // Create second tenant
        $keys2 = TenantKey::generateEnvelopeKeys();
        $tenant2 = TenantKey::create($keys2);

        $customer1 = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $customer2 = Customer::factory()->create([
            'tenant_id' => $tenant2->id,
        ]);

        // Assignment in current tenant
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer1->id,
            'user_id' => $this->user->id,
        ]);

        // Assignment in different tenant (should NOT appear)
        CustomerAssignment::factory()->create([
            'tenant_id' => $tenant2->id,
            'customer_id' => $customer2->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/customer-assignments');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['customer']['id'])->toBe($customer1->id);
    });
});

describe('GET /v1/me/site-assignments', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/me/site-assignments');
        $response->assertStatus(401);
    });

    test('returns authenticated users site assignments', function (): void {
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        // Create 2 assignments for authenticated user with explicit roles
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'role' => 'Site Manager',
        ]);
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'role' => 'Operations Lead',
        ]);

        // Create 1 assignment for another user (should NOT appear)
        $otherUser = User::factory()->create();
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/site-assignments');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'role', 'is_active', 'user', 'site'],
                ],
            ]);

        expect($response->json('data'))->toHaveCount(2);
        expect($response->json('data')[0]['user']['id'])->toBe($this->user->id);
    });

    test('includes eager-loaded site and site.customer relationships', function (): void {
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Acme Corp',
        ]);

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'name' => 'Main Facility',
        ]);

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/site-assignments');

        $response->assertOk();
        expect($response->json('data')[0]['site']['name'])->toBe('Main Facility');
        expect($response->json('data')[0]['site']['customer']['name'])->toBe('Acme Corp');
    });

    test('filters by active_only parameter', function (): void {
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        // Active assignment
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'valid_from' => null,
            'valid_until' => null,
        ]);

        // Expired assignment
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'valid_until' => now()->subDay(),
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/site-assignments?active_only=1');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['is_active'])->toBeTrue();
    });

    test('returns empty array when user has no assignments', function (): void {
        $response = $this->withToken($this->token)
            ->getJson('/v1/me/site-assignments');

        $response->assertOk();
        expect($response->json('data'))->toBeArray();
        expect($response->json('data'))->toHaveCount(0);
    });

    test('returns only assignments for current tenant', function (): void {
        // Create second tenant
        $keys2 = TenantKey::generateEnvelopeKeys();
        $tenant2 = TenantKey::create($keys2);

        $customer1 = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $customer2 = Customer::factory()->create([
            'tenant_id' => $tenant2->id,
        ]);

        $site1 = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer1->id,
        ]);

        $site2 = Site::factory()->create([
            'tenant_id' => $tenant2->id,
            'customer_id' => $customer2->id,
        ]);

        // Assignment in current tenant
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site1->id,
            'user_id' => $this->user->id,
        ]);

        // Assignment in different tenant (should NOT appear)
        SiteAssignment::factory()->create([
            'tenant_id' => $tenant2->id,
            'site_id' => $site2->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/site-assignments');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['site']['id'])->toBe($site1->id);
    });
});
