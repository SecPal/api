<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
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
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property string $token
 */
beforeEach(function (): void {
    incrementTestKekCounter();
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
            'billing_address' => [
                'street' => 'Teststr. 123',
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
        ]);

        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/customer-assignments');

        $response->assertOk();
        expect($response->json('data')[0]['customer'])->toMatchArray([
            'name' => 'Acme Corp',
            'billing_address' => [
                'street' => 'Teststr. 123',
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
            'deleted_at' => null,
        ]);
        expect($response->json('data')[0]['customer'])->toHaveKeys([
            'id',
            'legal_entity_id',
            'customer_number',
            'name',
            'billing_address',
            'is_active',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);
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
            'role' => 'Active Account Manager',
            'valid_from' => null,
            'valid_until' => null,
        ]);

        // Expired assignment
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'role' => 'Former Account Manager',
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

    test('bounds customer resource query count for assignment collections', function (): void {
        foreach (range(1, 8) as $index) {
            $customer = Customer::factory()->create([
                'tenant_id' => $this->tenant->id,
            ]);

            CustomerAssignment::factory()->create([
                'tenant_id' => $this->tenant->id,
                'customer_id' => $customer->id,
                'user_id' => $this->user->id,
                'role' => "Account Manager {$index}",
            ]);
        }

        DB::enableQueryLog();

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/customer-assignments');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(8);
        expect(count($queries))->toBeLessThan(12);
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
            'billing_address' => [
                'street' => 'Client Street 5',
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
        ]);

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'name' => 'Main Facility',
            'type' => 'temporary',
            'address' => [
                'street' => 'Guard Lane 7',
                'city' => 'Hamburg',
                'postal_code' => '20095',
                'country' => 'DE',
            ],
            'contact' => [
                'name' => 'Bob Site',
                'email' => 'bob.site@secpal.dev',
                'phone' => '+49 40 987654',
                'position' => 'Operations Lead',
            ],
            'access_instructions' => 'Gate 5, badge required',
            'notes' => 'Site-internal note',
            'metadata' => ['zone' => 'north'],
            'valid_from' => '2026-04-01',
            'valid_until' => now()->addWeek()->toDateString(),
        ]);

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/site-assignments');

        $response->assertOk();
        expect($response->json('data')[0]['site'])->toMatchArray([
            'customer_id' => $customer->id,
            'legal_entity_id' => $site->legal_entity_id,
            'establishment_id' => $site->establishment_id,
            'name' => 'Main Facility',
            'type' => 'temporary',
            'address' => [
                'street' => 'Guard Lane 7',
                'city' => 'Hamburg',
                'postal_code' => '20095',
                'country' => 'DE',
            ],
            'contact' => [
                'name' => 'Bob Site',
                'email' => 'bob.site@secpal.dev',
                'phone' => '+49 40 987654',
                'position' => 'Operations Lead',
            ],
            'access_instructions' => 'Gate 5, badge required',
            'notes' => 'Site-internal note',
            'metadata' => ['zone' => 'north'],
            'is_expired' => false,
            'deleted_at' => null,
        ]);
        expect($response->json('data')[0]['site'])->toHaveKeys([
            'id',
            'customer_id',
            'legal_entity_id',
            'establishment_id',
            'site_number',
            'name',
            'type',
            'address',
            'full_address',
            'contact',
            'access_instructions',
            'notes',
            'metadata',
            'is_active',
            'valid_from',
            'valid_until',
            'is_expired',
            'customer',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);
        expect($response->json('data')[0]['site']['full_address'])->toBe('Guard Lane 7, 20095, Hamburg');
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
            'role' => 'Active Site Manager',
            'valid_from' => null,
            'valid_until' => null,
        ]);

        // Expired assignment
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'role' => 'Former Site Manager',
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

    test('bounds site and nested customer resource query count for assignment collections', function (): void {
        foreach (range(1, 8) as $index) {
            $customer = Customer::factory()->create([
                'tenant_id' => $this->tenant->id,
            ]);

            $site = Site::factory()->create([
                'tenant_id' => $this->tenant->id,
                'customer_id' => $customer->id,
            ]);

            SiteAssignment::factory()->create([
                'tenant_id' => $this->tenant->id,
                'site_id' => $site->id,
                'user_id' => $this->user->id,
                'role' => "Site Manager {$index}",
            ]);
        }

        DB::enableQueryLog();

        $response = $this->withToken($this->token)
            ->getJson('/v1/me/site-assignments');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(8);
        expect(count($queries))->toBeLessThanOrEqual(14);
    });
});
