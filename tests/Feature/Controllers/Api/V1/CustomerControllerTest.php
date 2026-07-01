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
use App\Support\LikePattern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property mixed $token
 */
uses(RefreshDatabase::class);

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

describe('GET /v1/customers', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/customers');
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks customers.read permission and has no scoped customer access', function (): void {
        $response = $this->withToken($this->token)->getJson('/v1/customers');
        $response->assertForbidden();
    });

    test('returns paginated customers with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        Customer::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/customers');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'customer_number', 'name', 'billing_address', 'is_active'],
                ],
                'links',
                'meta',
            ]);
    });

    test('returns linked sites_count in customer list responses', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Site::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/customers');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.sites_count', 2);
    });

    test('returns preserved assignment history with null nested users in customer resources', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => null,
            'role' => 'Former Account Manager',
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/customers');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.assignments.0.user', null);
    });

    test('filters customers by is_active status', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => false,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/customers?is_active=1');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['is_active'])->toBeTrue();
    });

    test('searches customers by name', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Acme Corporation',
        ]);

        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tech Industries GmbH',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/customers?search=acme');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['name'])->toBe('Acme Corporation');
    });

    test('searches customers by customer_number', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $customer1 = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/customers?search={$customer1->customer_number}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['customer_number'])->toBe($customer1->customer_number);
    });

    test('treats wildcard-only customer search input as a literal string', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/customers?search='.urlencode('%%%%%'));

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(0);
    });

    test('binds escaped like patterns for literal backslash wildcard customer searches', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Literal foo\\%_bar customer',
        ]);

        DB::enableQueryLog();

        $response = $this->withToken($this->token)
            ->getJson('/v1/customers?search='.urlencode('foo\%_bar'));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();

        $bindings = collect($queries)
            ->pluck('bindings')
            ->flatten(1)
            ->filter(fn (mixed $binding): bool => is_string($binding));

        expect($bindings)->toContain('%'.LikePattern::escape('foo\%_bar').'%');
    });

    test('user without permission only sees assigned customers', function (): void {
        // User without customers.read permission
        $customer1 = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $customer2 = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        // Assign user to customer1
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer1->id,
            'user_id' => $this->user->id,
            'role' => 'Account Manager',
            'valid_from' => now()->subDays(10),
            'valid_until' => null,
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/customers');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['id'])->toBe($customer1->id);
    });

    test('user without permission can list customers via scoped site access', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'role' => 'Site Manager',
            'valid_from' => now()->subDays(10),
            'valid_until' => null,
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/customers');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['id'])->toBe($customer->id);
    });

    test('user without permission only receives visible sites_count in customer list', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $visibleSite = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $visibleSite->id,
            'user_id' => $this->user->id,
            'role' => 'Site Manager',
            'valid_from' => now()->subDays(10),
            'valid_until' => null,
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/customers');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.sites_count', 1);
    });

    test('user with sites read permission receives full customer sites_count in customer list', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $visibleSite = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $visibleSite->id,
            'user_id' => $this->user->id,
            'role' => 'Site Manager',
            'valid_from' => now()->subDays(10),
            'valid_until' => null,
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/customers');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.sites_count', 2);
    });

    test('supports pagination with custom per_page', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        Customer::factory()->count(20)->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/customers?per_page=5');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(5);
        expect($response->json('meta.per_page'))->toBe(5);
        expect($response->json('meta.total'))->toBe(20);
    });

    test('returns 422 when per_page exceeds maximum', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/customers?per_page=1000');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    });
});

describe('POST /v1/customers', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson('/v1/customers', [
            'name' => 'New Customer',
            'billing_address' => [
                'street' => 'Main St 1',
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks customers.create permission', function (): void {
        $response = $this->withToken($this->token)->postJson('/v1/customers', [
            'name' => 'New Customer',
            'billing_address' => [
                'street' => 'Main St 1',
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
        ]);

        $response->assertStatus(403);
    });

    test('returns 422 when required fields are missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/customers', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'billing_address',
            ]);
    });

    test('returns 422 when billing_address is incomplete', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/customers', [
                'name' => 'New Customer',
                'billing_address' => [
                    'street' => 'Main St 1',
                    // Missing city, postal_code, country
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'billing_address.city',
                'billing_address.postal_code',
                'billing_address.country',
            ]);
    });

    test('creates customer with auto-generated customer_number', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/customers', [
                'name' => 'Acme Corporation',
                'billing_address' => [
                    'street' => 'Main Street 123',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DE',
                ],
                'contact' => [
                    'name' => 'John Doe',
                    'email' => 'john@acme.com',
                    'phone' => '+49 30 12345678',
                ],
                'notes' => 'Important customer',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'customer_number',
                    'name',
                    'billing_address',
                    'contact',
                    // notes only visible with customers.update permission
                    'is_active',
                    'created_at',
                ],
            ]);

        $customerNumber = $response->json('data.customer_number');
        expect($customerNumber)->toMatch('/^KD-\d{4}-\d{4}$/');
        expect($response->json('data.name'))->toBe('Acme Corporation');
        expect($response->json('data.is_active'))->toBeTrue();
    });

    test('creates customer with custom customer_number', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/customers', [
                'name' => 'Tech Industries',
                'customer_number' => 'CUSTOM-001',
                'billing_address' => [
                    'street' => 'Tech Ave 42',
                    'city' => 'Munich',
                    'postal_code' => '80331',
                    'country' => 'DE',
                ],
            ]);

        $response->assertCreated();
        expect($response->json('data.customer_number'))->toBe('CUSTOM-001');
    });

    test('generates unique customer_number per tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.create');

        $response1 = $this->withToken($this->token)
            ->postJson('/v1/customers', [
                'name' => 'First Customer',
                'billing_address' => [
                    'street' => 'Street 1',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DE',
                ],
            ]);

        $response2 = $this->withToken($this->token)
            ->postJson('/v1/customers', [
                'name' => 'Second Customer',
                'billing_address' => [
                    'street' => 'Street 2',
                    'city' => 'Hamburg',
                    'postal_code' => '20095',
                    'country' => 'DE',
                ],
            ]);

        $response1->assertCreated();
        $response2->assertCreated();

        $number1 = $response1->json('data.customer_number');
        $number2 = $response2->json('data.customer_number');

        expect($number1)->not->toBe($number2);
        expect($number1)->toMatch('/^KD-\d{4}-\d{4}$/');
        expect($number2)->toMatch('/^KD-\d{4}-\d{4}$/');
    });

    test('validates country code format (ISO 3166-1 alpha-2)', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/customers', [
                'name' => 'Invalid Customer',
                'billing_address' => [
                    'street' => 'Street 1',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DEU', // Invalid: should be 2 characters
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['billing_address.country']);
    });
});

describe('GET /v1/customers/{customer}', function () {
    test('returns 401 when not authenticated', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->getJson("/v1/customers/{$customer->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user cannot view customer', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}");
        $response->assertStatus(403);
    });

    test('returns customer details with relationships when authorized', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        // Assign user to customer
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'role' => 'Account Manager',
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'customer_number',
                    'name',
                    'billing_address',
                    'contact',
                    'notes',
                    'is_active',
                    'assignments',
                    'created_at',
                    'updated_at',
                ],
            ]);

        expect($response->json('data.id'))->toBe($customer->id);
    });

    test('returns linked sites_count in customer detail responses', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'role' => 'Account Manager',
        ]);

        Site::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('data.sites_count', 3);
    });

    test('user with sites read permission receives full customer sites_count in customer detail', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $visibleSite = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $visibleSite->id,
            'user_id' => $this->user->id,
            'role' => 'Site Manager',
            'valid_from' => now()->subDays(10),
            'valid_until' => null,
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('data.sites_count', 2);
    });

    test('includes notes when user can update customer', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'notes' => 'Confidential notes',
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}");

        $response->assertOk();
        expect($response->json('data.notes'))->toBe('Confidential notes');
    });

    test('hides notes when user cannot update customer', function (): void {
        // Give read permission but not update permission
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'notes' => 'Confidential notes',
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}");

        $response->assertOk();
        expect($response->json('data'))->not->toHaveKey('notes');
    });

    test('returns 404 when user tries to access customer from different tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $customer = Customer::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}");

        $response->assertNotFound();
    });

    test('returns 404 for non-existent customer', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $fakeId = '00000000-0000-0000-0000-000000000000';
        $response = $this->withToken($this->token)->getJson("/v1/customers/{$fakeId}");

        $response->assertStatus(404);
    });

    test('returns 404 for invalid customer id format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $response = $this->withToken($this->token)->getJson('/v1/customers/1');

        $response->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
            ]);
    });
});

describe('PATCH /v1/customers/{customer}', function () {
    test('returns 401 when not authenticated', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->patchJson("/v1/customers/{$customer->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user cannot update customer', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withToken($this->token)->patchJson("/v1/customers/{$customer->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(403);
    });

    test('updates customer when user has permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withToken($this->token)->patchJson("/v1/customers/{$customer->id}", [
            'name' => 'Updated Corporation',
        ]);

        $response->assertOk();
        expect($response->json('data.name'))->toBe('Updated Corporation');

        $customer->refresh();
        expect($customer->name)->toBe('Updated Corporation');
    });

    test('updates customer when user is assigned', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'role' => 'Account Manager',
        ]);

        $response = $this->withToken($this->token)->patchJson("/v1/customers/{$customer->id}", [
            'name' => 'Updated via Assignment',
        ]);

        $response->assertOk();
        expect($response->json('data.name'))->toBe('Updated via Assignment');
    });

    test('allows partial updates', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Original Name',
            'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->patchJson("/v1/customers/{$customer->id}", [
            'is_active' => false,
        ]);

        $response->assertOk();
        expect($response->json('data.name'))->toBe('Original Name');
        expect($response->json('data.is_active'))->toBeFalse();
    });

    test('validates billing_address when provided', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withToken($this->token)->patchJson("/v1/customers/{$customer->id}", [
            'billing_address' => [
                'street' => 'New Street',
                // Missing required fields
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'billing_address.city',
                'billing_address.postal_code',
                'billing_address.country',
            ]);
    });
});

describe('DELETE /v1/customers/{customer}', function () {
    test('returns 401 when not authenticated', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->deleteJson("/v1/customers/{$customer->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks customers.delete permission', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withToken($this->token)->deleteJson("/v1/customers/{$customer->id}");
        $response->assertStatus(403);
    });

    test('soft deletes customer when authorized', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.delete');

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withToken($this->token)->deleteJson("/v1/customers/{$customer->id}");

        $response->assertNoContent();

        expect(Customer::withTrashed()->find($customer->id))->not->toBeNull();
        expect(Customer::find($customer->id))->toBeNull();
    });

    test('returns 409 when customer has active sites', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.delete');

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        // Create active site for customer
        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->deleteJson("/v1/customers/{$customer->id}");

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Cannot delete customer with active sites.',
            ]);

        expect(Customer::find($customer->id))->not->toBeNull();
    });

    test('allows deletion when customer has only inactive sites', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.delete');

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        // Create inactive site
        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'is_active' => false,
        ]);

        $response = $this->withToken($this->token)->deleteJson("/v1/customers/{$customer->id}");

        $response->assertNoContent();
        expect(Customer::find($customer->id))->toBeNull();
    });

    test('returns 404 for non-existent customer', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.delete');

        $fakeId = '00000000-0000-0000-0000-000000000000';
        $response = $this->withToken($this->token)->deleteJson("/v1/customers/{$fakeId}");

        $response->assertStatus(404);
    });
});

describe('GET /v1/customers/{customer}/sites', function () {
    test('returns 401 when not authenticated', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->getJson("/v1/customers/{$customer->id}/sites");
        $response->assertStatus(401);
    });

    test('returns 403 when user cannot view customer', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}/sites");
        $response->assertStatus(403);
    });

    test('returns paginated sites for customer', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        // Assign user to customer
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'role' => 'Account Manager',
        ]);

        Site::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}/sites");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'site_number', 'name', 'address', 'is_active'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        expect($response->json('data'))->toHaveCount(3);
    });

    test('returns only independently visible customer sites for scoped users', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $visibleSite = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $visibleSite->id,
            'user_id' => $this->user->id,
            'role' => 'Site Manager',
            'valid_from' => now()->subDay(),
            'valid_until' => null,
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}/sites");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($visibleSite->id);
    });

    test('filters customer sites by type', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'role' => 'Account Manager',
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'type' => 'permanent',
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'type' => 'temporary',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/customers/{$customer->id}/sites?type=temporary");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.type'))->toBe('temporary');
    });

    test('filters customer sites by active status', function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'role' => 'Account Manager',
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'is_active' => false,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/customers/{$customer->id}/sites?is_active=0");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.is_active'))->toBeFalse();
    });

    test('supports custom per_page for sites', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        Site::factory()->count(10)->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/customers/{$customer->id}/sites?per_page=5");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(5);
        expect($response->json('meta.per_page'))->toBe(5);
        expect($response->json('meta.total'))->toBe(10);
    });

    test('returns empty array when customer has no sites', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}/sites");

        $response->assertOk();
        expect($response->json('data'))->toBeArray();
        expect($response->json('data'))->toHaveCount(0);
    });

    test('returns 422 for invalid type filter on customer sites', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/customers/{$customer->id}/sites?type=invalid");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    });
});
