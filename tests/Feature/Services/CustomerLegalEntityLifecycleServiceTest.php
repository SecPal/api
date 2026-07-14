<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Models\Customer;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use App\Services\CustomerService;
use App\Services\OrganizationalUnitCustomerService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{name: string, legal_entity_id: string, billing_address: array{street: string, city: string, postal_code: string, country: string}}
 */
function lifecycleCustomerAttributes(OrganizationalUnit $legalEntity): array
{
    return [
        'name' => 'Lifecycle Customer',
        'legal_entity_id' => $legalEntity->id,
        'billing_address' => [
            'street' => 'Main Street 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
        ],
    ];
}

test('customer creation locks and revalidates the selected legal entity inside the transaction', function (): void {
    $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $legalEntity = OrganizationalUnit::factory()->forTenant((string) $tenant->id)->create([
        'is_legal_entity' => true,
        'is_active' => true,
    ]);
    UserInternalOrganizationalScope::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'organizational_unit_id' => $legalEntity->id,
        'access_level' => 'write',
    ]);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    app(CustomerService::class)->create(
        $user,
        (int) $tenant->id,
        lifecycleCustomerAttributes($legalEntity)
    );

    expect(collect($queries)->contains(
        fn (string $query): bool => str_contains($query, 'organizational_units')
            && str_contains(strtolower($query), 'for update')
    ))->toBeTrue();

    $legalEntity->update(['is_active' => false]);

    expect(fn () => app(CustomerService::class)->create(
        $user,
        (int) $tenant->id,
        lifecycleCustomerAttributes($legalEntity)
    ))->toThrow(ValidationException::class);

    $legalEntity->update([
        'is_active' => true,
        'is_assignable' => false,
    ]);

    expect(fn () => app(CustomerService::class)->create(
        $user,
        (int) $tenant->id,
        lifecycleCustomerAttributes($legalEntity)
    ))->toThrow(ValidationException::class);
});

test('organizational unit customer lifecycle mutations lock the legal entity row', function (): void {
    $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $legalEntity = OrganizationalUnit::factory()->forTenant((string) $tenant->id)->create([
        'is_legal_entity' => true,
        'is_active' => true,
    ]);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $updated = app(OrganizationalUnitCustomerService::class)->update($legalEntity, [
        'name' => 'Renamed Legal Entity',
    ]);

    expect($updated)->not->toBeNull()
        ->and(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'organizational_units')
                && str_contains(strtolower($query), 'for update')
        ))->toBeTrue();
});

test('soft deleted customers keep their legal entity restorable', function (): void {
    $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $legalEntity = OrganizationalUnit::factory()->forTenant((string) $tenant->id)->create([
        'is_legal_entity' => true,
        'is_active' => true,
    ]);
    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $customer->delete();

    $service = app(OrganizationalUnitCustomerService::class);

    expect($service->update($legalEntity, ['is_active' => false]))->toBeNull()
        ->and($service->delete($legalEntity))->toBeFalse();
});
