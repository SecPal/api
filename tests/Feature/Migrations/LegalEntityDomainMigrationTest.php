<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\TenantKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

test('creates tenant-local legal entity domain tables and removes customer-local and OU columns', function (): void {
    expect(Schema::hasColumns('legal_entities', ['id', 'tenant_id', 'name', 'is_active', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('establishments', ['id', 'tenant_id', 'legal_entity_id', 'name', 'is_active', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('customer_establishments', ['id', 'tenant_id', 'legal_entity_id', 'customer_id', 'establishment_id', 'contact_name', 'phone', 'email', 'comments', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('customers', ['tenant_id', 'legal_entity_id', 'customer_number', 'name', 'vat_id', 'billing_address', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'contact'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'notes'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'metadata'))->toBeFalse()
        ->and(Schema::hasColumns('sites', ['legal_entity_id', 'establishment_id']))->toBeTrue()
        ->and(Schema::hasColumn('sites', 'organizational_unit_id'))->toBeFalse()
        ->and(Schema::hasColumns('employees', ['legal_entity_id', 'establishment_id']))->toBeTrue()
        ->and(Schema::hasColumn('employees', 'organizational_unit_id'))->toBeFalse();
});

test('prevents duplicate customer establishment assignments', function (): void {
    $link = CustomerEstablishment::factory()->create();

    CustomerEstablishment::factory()->create([
        'tenant_id' => $link->tenant_id,
        'legal_entity_id' => $link->getRawOriginal('legal_entity_id'),
        'customer_id' => $link->customer_id,
        'establishment_id' => $link->establishment_id,
    ]);
})->throws(QueryException::class);

test('rejects customer establishment assignments across legal entities', function (): void {
    $customer = Customer::factory()->create();
    $otherLegalEntity = LegalEntity::factory()->create(['tenant_id' => $customer->tenant_id]);
    $establishment = Establishment::factory()->create([
        'tenant_id' => $customer->tenant_id,
        'legal_entity_id' => $otherLegalEntity->id,
    ]);

    DB::table('customer_establishments')->insert([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $customer->tenant_id,
        'legal_entity_id' => $customer->legal_entity_id,
        'customer_id' => $customer->id,
        'establishment_id' => $establishment->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

test('rejects cross-tenant establishment and customer legal entity relationships', function (string $table): void {
    $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreignLegalEntity = LegalEntity::factory()->forTenant($otherTenant->id)->create();

    $attributes = [
        'id' => Str::uuid()->toString(),
        'tenant_id' => $tenant->id,
        'legal_entity_id' => $foreignLegalEntity->id,
        'name' => 'Cross-tenant record',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    if ($table === 'customers') {
        $attributes += [
            'customer_number' => 'KD-CROSS-TENANT',
            'billing_address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
        ];
    }

    DB::table($table)->insert($attributes);
})->with(['establishments', 'customers'])->throws(QueryException::class);

test('rejects a site unless its customer and establishment are linked under its legal entity', function (): void {
    $customerEstablishment = CustomerEstablishment::factory()->create();
    $otherEstablishment = Establishment::factory()->create([
        'tenant_id' => $customerEstablishment->tenant_id,
        'legal_entity_id' => $customerEstablishment->customer->legal_entity_id,
    ]);

    DB::table('sites')->insert([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $customerEstablishment->tenant_id,
        'customer_id' => $customerEstablishment->customer_id,
        'legal_entity_id' => $customerEstablishment->customer->legal_entity_id,
        'establishment_id' => $otherEstablishment->id,
        'site_number' => 'OBJ-UNLINKED',
        'name' => 'Unlinked site',
        'type' => 'permanent',
        'address' => json_encode(['street' => 'Test', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

test('breaking migration declares a fail-fast guard and no data migration or OU compatibility path', function (): void {
    $migration = file_get_contents(base_path('database/migrations/2026_07_17_120000_create_legal_entity_domain_model.php'));

    expect($migration)->toContain("DB::table('customers')->exists()")
        ->and($migration)->toContain("DB::table('sites')->exists()")
        ->and($migration)->toContain("DB::table('employees')->exists()")
        ->and($migration)->toContain('cannot migrate existing customers, sites, or employees')
        ->and($migration)->not->toContain('insertUsing')
        ->and($migration)->not->toContain('organizational_units');
});
