<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Site;
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
    $this->token = $this->user->createToken('customer-domain-test')->plainTextToken;
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.create');
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.delete');
    $this->legalEntity = LegalEntity::factory()->forTenant((string) $this->tenant->id)->create();
    $this->establishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function domainCustomerPayload(LegalEntity $legalEntity, array $overrides = []): array
{
    return array_replace_recursive([
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Muster Kunde GmbH',
        'vat_id' => 'DE 123-456-789',
        'billing_address' => [
            'street' => 'Hauptstraße 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
        ],
    ], $overrides);
}

test('customer duplicates return one neutral conflict response after normalization', function (): void {
    $first = $this->withToken($this->token)->postJson(
        '/v1/customers',
        domainCustomerPayload($this->legalEntity),
    );
    $first->assertCreated();

    $vatDuplicate = $this->withToken($this->token)->postJson(
        '/v1/customers',
        domainCustomerPayload($this->legalEntity, [
            'name' => 'Andere Firma',
            'vat_id' => 'de123456789',
            'billing_address' => ['street' => 'Nebenweg 8'],
        ]),
    );
    $masterDataDuplicate = $this->withToken($this->token)->postJson(
        '/v1/customers',
        domainCustomerPayload($this->legalEntity, [
            'vat_id' => null,
            'name' => '  muster   kunde gmbh ',
            'billing_address' => [
                'street' => ' hauptstraße   1 ',
                'city' => ' BERLIN ',
                'postal_code' => ' 10115 ',
                'country' => 'de',
            ],
        ]),
    );

    foreach ([$vatDuplicate, $masterDataDuplicate] as $response) {
        $response->assertConflict()->assertExactJson([
            'message' => 'A matching record already exists.',
            'code' => 'DUPLICATE_RESOURCE',
        ]);
    }
    expect(Customer::query()->count())->toBe(1);
});

test('customer uniqueness is scoped to tenant and legal entity and ignores empty VAT IDs', function (): void {
    $otherLegalEntity = LegalEntity::factory()->forTenant((string) $this->tenant->id)->create();

    $this->withToken($this->token)->postJson(
        '/v1/customers',
        domainCustomerPayload($this->legalEntity, ['vat_id' => '   ']),
    )->assertCreated();
    $this->withToken($this->token)->postJson(
        '/v1/customers',
        domainCustomerPayload($otherLegalEntity, ['vat_id' => 'DE 123-456-789']),
    )->assertCreated();
});

test('customer update cannot collide by either normalized identity', function (): void {
    Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'name' => 'Bestandskunde GmbH',
        'vat_id' => 'DE999888777',
        'billing_address' => [
            'street' => 'Bestandsweg 4',
            'city' => 'Hamburg',
            'postal_code' => '20095',
            'country' => 'DE',
        ],
    ]);
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);

    $vatResponse = $this->withToken($this->token)->patchJson("/v1/customers/{$customer->id}", [
        'vat_id' => 'de 999-888-777',
    ]);
    $nameAddressResponse = $this->withToken($this->token)->patchJson("/v1/customers/{$customer->id}", [
        'name' => ' bestandskunde   gmbh ',
        'billing_address' => [
            'street' => 'bestandsweg 4',
            'city' => 'hamburg',
            'postal_code' => '20095',
            'country' => 'de',
        ],
    ]);

    foreach ([$vatResponse, $nameAddressResponse] as $response) {
        $response->assertConflict()->assertExactJson([
            'message' => 'A matching record already exists.',
            'code' => 'DUPLICATE_RESOURCE',
        ]);
    }
});

test('customer CRUD embeds customer establishments and returns an empty collection when none exist', function (): void {
    $created = $this->withToken($this->token)->postJson(
        '/v1/customers',
        domainCustomerPayload($this->legalEntity),
    );

    $created->assertCreated()
        ->assertJsonPath('data.customer_establishments', []);

    $customer = Customer::query()->findOrFail($created->json('data.id'));
    $link = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $customer->id,
        'establishment_id' => $this->establishment->id,
        'contact_name_plain' => 'Lokaler Kontakt',
    ]);

    $this->withToken($this->token)->getJson('/v1/customers')
        ->assertOk()
        ->assertJsonPath('data.0.customer_establishments.0.id', $link->id);
    $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.customer_establishments.0.id', $link->id);
    $this->withToken($this->token)->patchJson("/v1/customers/{$customer->id}", [
        'name' => 'Aktualisierter Kunde GmbH',
    ])->assertOk()
        ->assertJsonPath('data.customer_establishments.0.id', $link->id);
});

test('customer establishment CRUD exposes local contacts through customer and dedicated resources', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);

    $created = $this->withToken($this->token)->postJson('/v1/customer-establishments', [
        'customer_id' => $customer->id,
        'establishment_id' => $this->establishment->id,
        'contact_name' => 'Lokaler Kontakt',
        'phone' => '+49 30 123456',
        'email' => 'local@example.com',
        'comments' => 'Nur für diese Betriebsstätte',
    ]);

    $created->assertCreated()
        ->assertJsonMissingPath('data.organizational_unit_id')
        ->assertJsonPath('data.customer_id', $customer->id)
        ->assertJsonPath('data.establishment_id', $this->establishment->id)
        ->assertJsonPath('data.contact_name', 'Lokaler Kontakt');

    $storedLink = CustomerEstablishment::query()->findOrFail($created->json('data.id'));
    foreach (['contact_name_enc', 'phone_enc', 'email_enc', 'comments_enc'] as $encryptedField) {
        expect($storedLink->getRawOriginal($encryptedField))->not->toContain('Lokaler Kontakt')
            ->not->toContain('local@example.com');
    }

    $linkId = $created->json('data.id');
    $this->withToken($this->token)
        ->patchJson("/v1/customer-establishments/{$linkId}", ['contact_name' => 'Neuer Kontakt'])
        ->assertOk()
        ->assertJsonPath('data.contact_name', 'Neuer Kontakt');

    $this->withToken($this->token)->getJson('/v1/customers')
        ->assertOk()
        ->assertJsonPath('data.0.customer_establishments.0.id', $linkId)
        ->assertJsonMissingPath('data.0.organizational_unit_id');
    $this->withToken($this->token)->getJson("/v1/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.customer_establishments.0.id', $linkId)
        ->assertJsonMissingPath('data.organizational_unit_id');
    $this->withToken($this->token)->getJson("/v1/customer-establishments/{$linkId}")
        ->assertOk()
        ->assertJsonPath('data.email', 'local@example.com');

    $this->withToken($this->token)
        ->deleteJson("/v1/customer-establishments/{$linkId}")
        ->assertNoContent();
    expect(CustomerEstablishment::query()->find($linkId))->toBeNull();
});

test('duplicate customer establishment pair uses the neutral conflict response', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
    $payload = [
        'customer_id' => $customer->id,
        'establishment_id' => $this->establishment->id,
    ];

    $this->withToken($this->token)->postJson('/v1/customer-establishments', $payload)->assertCreated();
    $this->withToken($this->token)->postJson('/v1/customer-establishments', $payload)
        ->assertConflict()
        ->assertExactJson([
            'message' => 'A matching record already exists.',
            'code' => 'DUPLICATE_RESOURCE',
        ]);
});

test('customer establishment deletion is blocked while sites reference the link', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
    $link = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $customer->id,
        'establishment_id' => $this->establishment->id,
    ]);
    Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $customer->id,
        'establishment_id' => $this->establishment->id,
    ]);

    $this->withToken($this->token)
        ->deleteJson("/v1/customer-establishments/{$link->id}")
        ->assertConflict();

    expect($link->fresh()?->trashed())->toBeFalse();
});

test('a deleted customer establishment can be recreated but cannot authorize a site while deleted', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
    $link = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $customer->id,
        'establishment_id' => $this->establishment->id,
        'contact_name_plain' => 'Stale Contact',
        'phone_plain' => '+49 30 000000',
        'email_plain' => 'stale@example.com',
        'comments_plain' => 'Stale comments',
    ]);
    $link->delete();

    givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');
    $this->withToken($this->token)->postJson('/v1/sites', [
        'name' => 'Rejected Site',
        'customer_id' => $customer->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
        'type' => 'permanent',
        'address' => [
            'street' => 'Main Street 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors(['establishment_id']);

    $this->withToken($this->token)->postJson('/v1/customer-establishments', [
        'customer_id' => $customer->id,
        'establishment_id' => $this->establishment->id,
        'contact_name' => 'Restored Contact',
    ])->assertCreated();

    expect($link->fresh()?->trashed())->toBeFalse()
        ->and($link->fresh()?->contact_name)->toBe('Restored Contact')
        ->and($link->fresh()?->phone)->toBeNull()
        ->and($link->fresh()?->email)->toBeNull()
        ->and($link->fresh()?->comments)->toBeNull();
});

test('customer establishment rejects cross-tenant and cross-legal-entity links', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
    $otherLegalEntity = LegalEntity::factory()->forTenant((string) $this->tenant->id)->create();
    $otherEstablishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $otherLegalEntity->id,
    ]);

    $this->withToken($this->token)->postJson('/v1/customer-establishments', [
        'customer_id' => $customer->id,
        'establishment_id' => $otherEstablishment->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['establishment_id']);
});

test('customer establishment rejects inactive customer domains', function (string $inactiveModel): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);

    match ($inactiveModel) {
        'customer' => $customer->update(['is_active' => false]),
        'legal entity' => $this->legalEntity->update(['is_active' => false]),
    };

    $this->withToken($this->token)->postJson('/v1/customer-establishments', [
        'customer_id' => $customer->id,
        'establishment_id' => $this->establishment->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['establishment_id']);

    $this->assertDatabaseMissing('customer_establishments', [
        'customer_id' => $customer->id,
        'establishment_id' => $this->establishment->id,
    ]);
})->with(['customer', 'legal entity']);
