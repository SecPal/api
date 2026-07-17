<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->user->createToken('domain-lookups')->plainTextToken;
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('domain lookups cascade through authorized same-tenant records with minimal payloads', function (): void {
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.create');
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

    $legalEntity = LegalEntity::factory()->forTenant((string) $this->tenant->id)->create([
        'name' => 'Allowed Legal Entity',
    ]);
    $establishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Allowed Establishment',
    ]);
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Allowed Customer',
    ]);
    CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $establishment->id,
        'customer_id' => $customer->id,
    ]);

    $otherEstablishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $unlinkedCustomer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $otherEstablishment->id,
        'customer_id' => $unlinkedCustomer->id,
    ]);

    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreignLegalEntity = LegalEntity::factory()->forTenant((string) $foreignTenant->id)->create();
    Establishment::factory()->create([
        'tenant_id' => $foreignTenant->id,
        'legal_entity_id' => $foreignLegalEntity->id,
    ]);

    $this->withToken($this->token)->getJson('/v1/lookups/legal-entities')
        ->assertOk()
        ->assertJsonFragment(['id' => $legalEntity->id, 'name' => 'Allowed Legal Entity'])
        ->assertJsonMissing(['id' => $foreignLegalEntity->id])
        ->assertJsonMissingPath('data.0.tenant_id');

    $this->withToken($this->token)
        ->getJson("/v1/lookups/legal-entities/{$legalEntity->id}/establishments")
        ->assertOk()
        ->assertJsonFragment(['id' => $establishment->id, 'name' => 'Allowed Establishment'])
        ->assertJsonMissingPath('data.0.legal_entity_id');

    $this->withToken($this->token)
        ->getJson("/v1/lookups/establishments/{$establishment->id}/customers")
        ->assertOk()
        ->assertExactJson(['data' => [[
            'id' => $customer->id,
            'name' => 'Allowed Customer',
        ]]])
        ->assertJsonMissing(['id' => $unlinkedCustomer->id]);
});

test('domain lookups reject foreign-tenant and ineffective organizational access', function (): void {
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.create');
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreignLegalEntity = LegalEntity::factory()->forTenant((string) $foreignTenant->id)->create();
    $foreignEstablishment = Establishment::factory()->create([
        'tenant_id' => $foreignTenant->id,
        'legal_entity_id' => $foreignLegalEntity->id,
    ]);

    $this->withToken($this->token)
        ->getJson("/v1/lookups/legal-entities/{$foreignLegalEntity->id}/establishments")
        ->assertNotFound();
    $this->withToken($this->token)
        ->getJson("/v1/lookups/establishments/{$foreignEstablishment->id}/customers")
        ->assertNotFound();

    $unit = OrganizationalUnit::factory()->forTenant((string) $this->tenant->id)->create();
    giveOrganizationalScope($this->user, $unit, accessLevel: 'write');

    $this->withToken($this->token)->getJson('/v1/lookups/legal-entities')->assertForbidden();
});

test('customer and customer establishment lists share effective assignment visibility', function (): void {
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

    $legalEntity = LegalEntity::factory()->forTenant((string) $this->tenant->id)->create();
    $establishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $visibleCustomer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $hiddenCustomer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $visibleLink = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $establishment->id,
        'customer_id' => $visibleCustomer->id,
    ]);
    $hiddenLink = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $establishment->id,
        'customer_id' => $hiddenCustomer->id,
    ]);
    CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $visibleCustomer->id,
        'user_id' => $this->user->id,
    ]);
    $unit = OrganizationalUnit::factory()->forTenant((string) $this->tenant->id)->create();
    giveOrganizationalScope($this->user, $unit, accessLevel: 'read');

    $this->withToken($this->token)->getJson('/v1/customers')
        ->assertOk()
        ->assertJsonFragment(['id' => $visibleCustomer->id])
        ->assertJsonMissing(['id' => $hiddenCustomer->id]);
    $this->withToken($this->token)->getJson('/v1/customer-establishments')
        ->assertOk()
        ->assertJsonFragment(['id' => $visibleLink->id])
        ->assertJsonMissing(['id' => $hiddenLink->id]);
});

test('site-scoped access does not expose other establishments of the same customer', function (): void {
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.create');

    $legalEntity = LegalEntity::factory()->forTenant((string) $this->tenant->id)->create();
    $visibleEstablishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $hiddenEstablishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $visibleLink = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $visibleEstablishment->id,
        'customer_id' => $customer->id,
        'contact_name_plain' => 'Hidden Contact',
        'email_plain' => 'hidden@example.com',
    ]);
    $hiddenLink = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $hiddenEstablishment->id,
        'customer_id' => $customer->id,
    ]);
    $site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $customer->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $visibleEstablishment->id,
    ]);
    SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $site->id,
        'user_id' => $this->user->id,
    ]);

    $this->withToken($this->token)->getJson('/v1/customer-establishments')
        ->assertOk()
        ->assertJsonFragment(['id' => $visibleLink->id])
        ->assertJsonMissing(['id' => $hiddenLink->id]);

    $this->withToken($this->token)->getJson('/v1/customers')
        ->assertOk()
        ->assertJsonMissingPath('data.0.customer_establishments');
    $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.customer_establishments');
    $this->withToken($this->token)
        ->getJson("/v1/customer-establishments/{$hiddenLink->id}")
        ->assertForbidden();

    $this->withToken($this->token)
        ->getJson("/v1/lookups/establishments/{$hiddenEstablishment->id}/customers")
        ->assertOk()
        ->assertExactJson(['data' => []]);
});
