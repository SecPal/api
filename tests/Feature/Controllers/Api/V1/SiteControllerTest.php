<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property string $token
 * @property OrganizationalUnit $orgUnit
 * @property Customer $customer
 * @property Establishment $establishment
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

    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    // Create organizational unit for site assignments
    $this->orgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    // Create customer for site assignments
    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->establishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->customer->legal_entity_id,
    ]);
    CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->customer->legal_entity_id,
        'customer_id' => $this->customer->id,
        'establishment_id' => $this->establishment->id,
    ]);
});

afterEach(function (): void {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/sites', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/sites');
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks sites.read permission and has no scoped site access', function (): void {
        $response = $this->withToken($this->token)->getJson('/v1/sites');
        $response->assertForbidden();
    });

    test('returns 403 before validating filters when user lacks sites.read permission and has no scoped site access', function (): void {
        $response = $this->withToken($this->token)
            ->getJson('/v1/sites?customer_id=not-a-uuid');

        $response->assertForbidden();
    });

    test('returns paginated sites with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        Site::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/sites');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'site_number',
                        'name',
                        'type',
                        'address',
                        'full_address',
                        'is_active',
                        'is_expired',
                        'customer_id',
                        'legal_entity_id',
                        'establishment_id',
                    ],
                ],
                'links',
                'meta',
            ]);
    });

    test('returns preserved assignment history with null nested users in site resources', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => null,
            'role' => 'Former Site Manager',
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/sites');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $site->id)
            ->assertJsonPath('data.0.assignments.0.user', null);
    });

    test('filters sites by is_active status', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'is_active' => true,
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'is_active' => false,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/sites?is_active=1');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['is_active'])->toBeTrue();
    });

    test('filters sites by type', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'type' => 'permanent',
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'type' => 'temporary',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/sites?type=permanent');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['type'])->toBe('permanent');
    });

    test('filters sites by customer_id', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $customer2 = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer2->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/sites?customer_id={$this->customer->id}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['customer_id'])->toBe($this->customer->id);
    });

    test('returns 422 for invalid customer_id filter format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/sites?customer_id=1');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);
    });

    test('returns empty list for foreign-tenant customer_id filter', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $foreignCustomer = Customer::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/sites?customer_id={$foreignCustomer->id}");

        $response->assertOk();
        expect($response->json('data'))->toBeArray();
        expect($response->json('data'))->toHaveCount(0);
    });

    test('filters sites by establishment_id', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $establishment2 = Establishment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $establishment2->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/sites?establishment_id={$this->establishment->id}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['establishment_id'])->toBe($this->establishment->id);
    });

    test('returns 422 for invalid establishment_id filter format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/sites?establishment_id=1');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['establishment_id']);
    });

    test('returns empty list for foreign-tenant establishment_id filter', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $foreignEstablishment = Establishment::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/sites?establishment_id={$foreignEstablishment->id}");

        $response->assertOk();
        expect($response->json('data'))->toBeArray();
        expect($response->json('data'))->toHaveCount(0);
    });

    test('searches sites by name', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'name' => 'Airport Terminal 1',
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'name' => 'Shopping Mall',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/sites?search=airport');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['name'])->toBe('Airport Terminal 1');
    });

    test('searches sites by site_number', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $site1 = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/sites?search={$site1->site_number}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['site_number'])->toBe($site1->site_number);
    });

    test('treats wildcard-only site search input as a literal string', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/sites?search='.urlencode('%%%%%'));

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(0);
    });

    test('user without permission only sees assigned sites', function (): void {
        // User without sites.read permission
        $site1 = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $site2 = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        // Assign user to site1
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site1->id,
            'user_id' => $this->user->id,
            'role' => 'Site Manager',
            'valid_from' => now()->subDays(10),
            'valid_until' => null,
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/sites');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['id'])->toBe($site1->id);
    });

    test('user without permission can list sites via customer assignment', function (): void {
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        App\Models\CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'role' => 'Key Account',
            'valid_from' => now()->subDays(10),
            'valid_until' => null,
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/sites');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['id'])->toBe($site->id);
    });

    test('supports pagination with custom per_page', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        Site::factory()->count(20)->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/sites?per_page=5');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(5);
        expect($response->json('meta.per_page'))->toBe(5);
        expect($response->json('meta.total'))->toBe(20);
    });
});

describe('POST /v1/sites', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson('/v1/sites', [
            'name' => 'New Site',
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'type' => 'permanent',
            'address' => [
                'street' => 'Main St 1',
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks sites.create permission', function (): void {
        $response = $this->withToken($this->token)->postJson('/v1/sites', [
            'name' => 'New Site',
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'type' => 'permanent',
            'address' => [
                'street' => 'Main St 1',
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
        ]);

        $response->assertStatus(403);
    });

    test('returns 422 when required fields are missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/sites', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'customer_id',
                'legal_entity_id',
                'establishment_id',
                'type',
                'address',
            ]);
    });

    test('rejects inactive or deleted site domain assignments', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $payload = [
            'name' => 'Invalid Domain Site',
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'type' => 'permanent',
            'address' => [
                'street' => 'Main St 1',
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
        ];

        $this->establishment->update(['is_active' => false]);
        $this->withToken($this->token)->postJson('/v1/sites', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['establishment_id']);

        $this->establishment->update(['is_active' => true]);
        $this->customer->update(['is_active' => false]);
        $this->withToken($this->token)->postJson('/v1/sites', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['establishment_id']);

        $this->customer->update(['is_active' => true]);
        $this->customer->legalEntity()->update(['is_active' => false]);
        $this->withToken($this->token)->postJson('/v1/sites', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['establishment_id']);

        $this->customer->legalEntity()->update(['is_active' => true]);
        $this->establishment->delete();
        $this->withToken($this->token)->postJson('/v1/sites', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['establishment_id']);

        expect(Site::query()->where('name', 'Invalid Domain Site')->exists())->toBeFalse();
    });

    test('rejects the legacy organizational unit field on site writes', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $this->withToken($this->token)->postJson('/v1/sites', [
            'name' => 'Legacy Domain Site',
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'organizational_unit_id' => $this->orgUnit->id,
            'type' => 'permanent',
            'address' => [
                'street' => 'Main St 1',
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['organizational_unit_id']);

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $this->withToken($this->token)
            ->patchJson("/v1/sites/{$site->id}", ['organizational_unit_id' => $this->orgUnit->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organizational_unit_id']);
    });

    test('returns 422 when address is incomplete', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/sites', [
                'name' => 'New Site',
                'customer_id' => $this->customer->id,
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => $this->establishment->id,
                'type' => 'permanent',
                'address' => [
                    'street' => 'Main St 1',
                    // Missing city, postal_code, country
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'address.city',
                'address.postal_code',
                'address.country',
            ]);
    });

    test('creates site with auto-generated site_number', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/sites', [
                'name' => 'Airport Terminal 1',
                'customer_id' => $this->customer->id,
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => $this->establishment->id,
                'type' => 'permanent',
                'address' => [
                    'street' => 'Airport Ring 1',
                    'city' => 'Berlin',
                    'postal_code' => '12529',
                    'country' => 'DE',
                    'latitude' => 52.3667,
                    'longitude' => 13.5033,
                ],
                'contact' => [
                    'name' => 'Jane Smith',
                    'email' => 'jane@airport.com',
                    'phone' => '+49 30 987654',
                ],
                'access_instructions' => 'Gate 5, Security Check',
                'notes' => 'Important location',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'site_number',
                    'name',
                    'type',
                    'address',
                    'full_address',
                    'is_active',
                    'is_expired',
                    'customer_id',
                    'legal_entity_id',
                    'establishment_id',
                    'created_at',
                ],
            ]);

        $siteNumber = $response->json('data.site_number');
        expect($siteNumber)->toMatch('/^OBJ-\d{4}-\d{4}$/');
        expect($response->json('data.name'))->toBe('Airport Terminal 1');
        expect($response->json('data.type'))->toBe('permanent');
        expect($response->json('data.is_active'))->toBeTrue();
    });

    test('creates site with custom site_number', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/sites', [
                'name' => 'Custom Site',
                'site_number' => 'CUSTOM-SITE-001',
                'customer_id' => $this->customer->id,
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => $this->establishment->id,
                'type' => 'temporary',
                'address' => [
                    'street' => 'Event Str 42',
                    'city' => 'Munich',
                    'postal_code' => '80331',
                    'country' => 'DE',
                ],
            ]);

        $response->assertCreated();
        expect($response->json('data.site_number'))->toBe('CUSTOM-SITE-001');
    });

    test('generates unique site_number per tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $response1 = $this->withToken($this->token)
            ->postJson('/v1/sites', [
                'name' => 'First Site',
                'customer_id' => $this->customer->id,
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => $this->establishment->id,
                'type' => 'permanent',
                'address' => [
                    'street' => 'Street 1',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DE',
                ],
            ]);

        $response2 = $this->withToken($this->token)
            ->postJson('/v1/sites', [
                'name' => 'Second Site',
                'customer_id' => $this->customer->id,
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => $this->establishment->id,
                'type' => 'permanent',
                'address' => [
                    'street' => 'Street 2',
                    'city' => 'Hamburg',
                    'postal_code' => '20095',
                    'country' => 'DE',
                ],
            ]);

        $response1->assertCreated();
        $response2->assertCreated();

        $number1 = $response1->json('data.site_number');
        $number2 = $response2->json('data.site_number');

        expect($number1)->not->toBe($number2);
        expect($number1)->toMatch('/^OBJ-\d{4}-\d{4}$/');
        expect($number2)->toMatch('/^OBJ-\d{4}-\d{4}$/');
    });

    test('validates type must be permanent or temporary', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/sites', [
                'name' => 'Invalid Site',
                'customer_id' => $this->customer->id,
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => $this->establishment->id,
                'type' => 'invalid-type',
                'address' => [
                    'street' => 'Street 1',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DE',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    });

    test('validates valid_until must be after valid_from', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/sites', [
                'name' => 'Temporary Site',
                'customer_id' => $this->customer->id,
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => $this->establishment->id,
                'type' => 'temporary',
                'valid_from' => '2025-06-01',
                'valid_until' => '2025-05-01', // Before valid_from
                'address' => [
                    'street' => 'Event Str 1',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DE',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);
    });

    test('validates customer_id must exist in tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/sites', [
                'name' => 'Invalid Site',
                'customer_id' => '550e8400-e29b-41d4-a716-446655440000', // Non-existent UUID
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => $this->establishment->id,
                'type' => 'permanent',
                'address' => [
                    'street' => 'Street 1',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DE',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);
    });

    test('validates establishment_id must exist in tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/sites', [
                'name' => 'Invalid Site',
                'customer_id' => $this->customer->id,
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => '550e8400-e29b-41d4-a716-446655440000', // Non-existent UUID
                'type' => 'permanent',
                'address' => [
                    'street' => 'Street 1',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DE',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['establishment_id']);
    });

    test('validates latitude range', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/sites', [
                'name' => 'Invalid GPS Site',
                'customer_id' => $this->customer->id,
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => $this->establishment->id,
                'type' => 'permanent',
                'address' => [
                    'street' => 'Street 1',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DE',
                    'latitude' => 95.0, // Invalid: > 90
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['address.latitude']);
    });

    test('validates longitude range', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');

        $response = $this->withToken($this->token)
            ->postJson('/v1/sites', [
                'name' => 'Invalid GPS Site',
                'customer_id' => $this->customer->id,
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => $this->establishment->id,
                'type' => 'permanent',
                'address' => [
                    'street' => 'Street 1',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DE',
                    'longitude' => 200.0, // Invalid: > 180
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['address.longitude']);
    });
});

describe('GET /v1/sites/{site}', function () {
    test('returns 401 when not authenticated', function (): void {
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->getJson("/v1/sites/{$site->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user cannot view site', function (): void {
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/sites/{$site->id}");
        $response->assertStatus(403);
    });

    test('returns site details with relationships when authorized', function (): void {
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        // Assign user to site
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'role' => 'Site Manager',
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/sites/{$site->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'site_number',
                    'name',
                    'type',
                    'address',
                    'full_address',
                    'access_instructions',
                    'notes',
                    'is_active',
                    'is_expired',
                    'customer',
                    'legal_entity_id',
                    'establishment_id',
                    'assignments',
                    'created_at',
                    'updated_at',
                ],
            ]);

        expect($response->json('data.id'))->toBe($site->id);
    });

    test('includes access_instructions and notes when user can update site', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'access_instructions' => 'Secret gate code: 1234',
            'notes' => 'Confidential notes',
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/sites/{$site->id}");

        $response->assertOk();
        expect($response->json('data.access_instructions'))->toBe('Secret gate code: 1234');
        expect($response->json('data.notes'))->toBe('Confidential notes');
    });

    test('hides access_instructions and notes when user cannot update site', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'access_instructions' => 'Secret gate code: 1234',
            'notes' => 'Confidential notes',
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/sites/{$site->id}");

        $response->assertOk();
        expect($response->json('data'))->not->toHaveKey('access_instructions');
        expect($response->json('data'))->not->toHaveKey('notes');
    });

    test('returns 404 when user tries to access site from different tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $otherTenantCustomer = Customer::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);
        $site = Site::factory()->create([
            'tenant_id' => $otherTenant->id,
            'customer_id' => $otherTenantCustomer->id,
        ]);

        $response = $this->withToken($this->token)->getJson("/v1/sites/{$site->id}");

        $response->assertNotFound();
    });

    test('returns 404 for non-existent site', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $fakeId = '00000000-0000-0000-0000-000000000000';
        $response = $this->withToken($this->token)->getJson("/v1/sites/{$fakeId}");

        $response->assertStatus(404);
    });

    test('returns 404 for invalid site id format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $response = $this->withToken($this->token)->getJson('/v1/sites/1');

        $response->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
            ]);
    });
});

describe('PATCH /v1/sites/{site}', function () {
    test('allows correcting past-only site coverage in a non-assignable organizational unit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        $this->orgUnit->update(['is_assignable' => false]);
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'valid_from' => now()->subWeeks(3),
            'valid_until' => now()->subWeeks(2),
        ]);

        $response = $this->withToken($this->token)->patchJson("/v1/sites/{$site->id}", [
            'valid_until' => now()->subWeek()->toDateString(),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.valid_until', now()->subWeek()->toDateString());
    });

    test('allows activating an expired site in a non-assignable organizational unit without future coverage', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        $this->orgUnit->update(['is_assignable' => false]);
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'is_active' => false,
            'valid_until' => now()->subDay(),
        ]);

        $response = $this->withToken($this->token)->patchJson("/v1/sites/{$site->id}", [
            'is_active' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_active', true);
    });

    test('allows reactivating a site while moving it to another establishment', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        $targetEstablishment = Establishment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
        ]);
        CustomerEstablishment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'customer_id' => $this->customer->id,
            'establishment_id' => $targetEstablishment->id,
        ]);
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'is_active' => false,
        ]);
        $response = $this->withToken($this->token)->patchJson("/v1/sites/{$site->id}", [
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $targetEstablishment->id,
            'is_active' => true,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'establishment_id' => $targetEstablishment->id,
            'is_active' => true,
        ]);
    });

    test('allows an unchanged non-assignable organizational unit in a site update', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        $this->orgUnit->update(['is_assignable' => false]);
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/sites/{$site->id}", [
                'legal_entity_id' => $this->customer->legal_entity_id,
                'establishment_id' => $this->establishment->id,
                'name' => 'Updated Terminal',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Terminal');
    });

    test('returns 401 when not authenticated', function (): void {
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->patchJson("/v1/sites/{$site->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user cannot update site', function (): void {
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)->patchJson("/v1/sites/{$site->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(403);
    });

    test('updates site when user has permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)->patchJson("/v1/sites/{$site->id}", [
            'name' => 'Updated Terminal',
        ]);

        $response->assertOk();
        expect($response->json('data.name'))->toBe('Updated Terminal');

        $site->refresh();
        expect($site->name)->toBe('Updated Terminal');
    });

    test('updates site when user is assigned', function (): void {
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'role' => 'Site Manager',
        ]);

        $response = $this->withToken($this->token)->patchJson("/v1/sites/{$site->id}", [
            'name' => 'Updated via Assignment',
        ]);

        $response->assertOk();
        expect($response->json('data.name'))->toBe('Updated via Assignment');
    });

    test('allows partial updates', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'name' => 'Original Name',
            'is_active' => true,
        ]);

        $response = $this->withToken($this->token)->patchJson("/v1/sites/{$site->id}", [
            'is_active' => false,
        ]);

        $response->assertOk();
        expect($response->json('data.name'))->toBe('Original Name');
        expect($response->json('data.is_active'))->toBeFalse();
    });

    test('rejects a partial customer reassignment when the resulting domain tuple is invalid', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);
        $otherCustomer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->withToken($this->token)
            ->patchJson("/v1/sites/{$site->id}", ['customer_id' => $otherCustomer->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['establishment_id']);

        expect($site->refresh()->customer_id)->toBe($this->customer->id);
    });

    test('validates address when provided', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)->patchJson("/v1/sites/{$site->id}", [
            'address' => [
                'street' => 'New Street',
                // Missing required fields
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'address.city',
                'address.postal_code',
                'address.country',
            ]);
    });

    test('validates valid_until against existing valid_from in partial updates', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
            'valid_from' => '2025-01-01',
            'valid_until' => null,
        ]);

        // Try to set valid_until before existing valid_from
        $response = $this->withToken($this->token)->patchJson("/v1/sites/{$site->id}", [
            'valid_until' => '2024-12-31', // Before existing valid_from (2025-01-01)
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);
    });
});

describe('DELETE /v1/sites/{site}', function () {
    test('returns 401 when not authenticated', function (): void {
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->deleteJson("/v1/sites/{$site->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks sites.delete permission', function (): void {
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)->deleteJson("/v1/sites/{$site->id}");
        $response->assertStatus(403);
    });

    test('soft deletes site when authorized', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.delete');

        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $this->customer->legal_entity_id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)->deleteJson("/v1/sites/{$site->id}");

        $response->assertNoContent();

        expect(Site::withTrashed()->find($site->id))->not->toBeNull();
        expect(Site::find($site->id))->toBeNull();
    });

    // Note: Active cost centers blocking tests will be added when CostCenter CRUD endpoints are implemented

    test('returns 404 for non-existent site', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.delete');

        $fakeId = '00000000-0000-0000-0000-000000000000';
        $response = $this->withToken($this->token)->deleteJson("/v1/sites/{$fakeId}");

        $response->assertStatus(404);
    });
});
