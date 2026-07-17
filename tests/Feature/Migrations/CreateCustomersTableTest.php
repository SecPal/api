<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\TenantKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('customers stores only legal-entity-wide master data', function (): void {
    expect(Schema::hasColumns('customers', [
        'id', 'tenant_id', 'legal_entity_id', 'customer_number', 'name', 'vat_id',
        'billing_address', 'is_active', 'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'contact'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'notes'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'metadata'))->toBeFalse();
});

test('customers has tenant scoped identifiers and legal entity foreign key', function (): void {
    $indexes = collect(Schema::getIndexes('customers'));
    $foreignKeys = collect(Schema::getForeignKeys('customers'));

    expect($indexes->contains(fn (array $index): bool => $index['name'] === 'unique_tenant_customer_number'
        && $index['unique'] === true
        && $index['columns'] === ['tenant_id', 'customer_number']))->toBeTrue()
        ->and($foreignKeys->contains(fn (array $foreignKey): bool => $foreignKey['foreign_table'] === 'legal_entities'
            && $foreignKey['columns'] === ['tenant_id', 'legal_entity_id']))->toBeTrue();
});

test('customers has atomic normalized duplicate guards per tenant and legal entity', function (): void {
    $columns = collect(Schema::getColumns('customers'))->keyBy('name');
    $indexes = collect(Schema::getIndexes('customers'))->keyBy('name');

    expect($columns)->toHaveKeys(['vat_id_normalized', 'name_billing_address_normalized'])
        ->and($indexes->get('customers_tenant_legal_entity_vat_normalized_unique')['unique'])->toBeTrue()
        ->and($indexes->get('customers_tenant_legal_entity_vat_normalized_unique')['columns'])
        ->toBe(['tenant_id', 'legal_entity_id', 'vat_id_normalized'])
        ->and($indexes->get('customers_tenant_legal_entity_name_address_normalized_unique')['unique'])->toBeTrue()
        ->and($indexes->get('customers_tenant_legal_entity_name_address_normalized_unique')['columns'])
        ->toBe(['tenant_id', 'legal_entity_id', 'name_billing_address_normalized']);
});

test('customers rejects a legal entity from another tenant', function (): void {
    $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreignLegalEntity = LegalEntity::factory()->forTenant($otherTenant->id)->create();

    Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'legal_entity_id' => $foreignLegalEntity->id,
    ]);
})->throws(QueryException::class);

test('tenant deletion cascades to customer master data', function (): void {
    $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

    $tenant->delete();

    expect(DB::table('customers')->where('id', $customer->id)->exists())->toBeFalse();
});
