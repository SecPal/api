<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\OrganizationalUnit;
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
    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');
    $this->token = $this->user->createToken('employee-domain-requests')->plainTextToken;

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

function employeeDomainPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Erika',
        'last_name' => 'Musterfrau',
        'date_of_birth' => '1990-01-01',
        'email' => 'erika.musterfrau@secpal.dev',
        'status' => Employee::STATUS_ACTIVE,
        'position' => 'Security Guard',
        'contract_start_date' => '2026-08-01',
        'contract_type' => 'full_time',
        'management_level' => 0,
    ], $overrides);
}

test('employee create requires both domain assignments without returning an OU form error', function (): void {
    $this->withToken($this->token)
        ->postJson('/v1/employees', employeeDomainPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['legal_entity_id', 'establishment_id'])
        ->assertJsonMissingPath('errors.organizational_unit_id');
});

test('employee create rejects an establishment belonging to another legal entity', function (): void {
    $otherLegalEntity = LegalEntity::factory()->forTenant((string) $this->tenant->id)->create();
    $otherEstablishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $otherLegalEntity->id,
    ]);

    $this->withToken($this->token)
        ->postJson('/v1/employees', employeeDomainPayload([
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $otherEstablishment->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['establishment_id']);
});

test('employee create rejects an inactive establishment', function (): void {
    $this->establishment->update(['is_active' => false]);

    $this->withToken($this->token)
        ->postJson('/v1/employees', employeeDomainPayload([
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['establishment_id']);
});

test('employee writes reject the legacy organizational unit field', function (): void {
    $organizationalUnit = OrganizationalUnit::factory()->forTenant((string) $this->tenant->id)->create();

    $this->withToken($this->token)
        ->postJson('/v1/employees', employeeDomainPayload([
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'organizational_unit_id' => $organizationalUnit->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['organizational_unit_id']);

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
        'management_level' => 0,
    ]);

    $this->withToken($this->token)
        ->patchJson("/v1/employees/{$employee->id}", [
            'organizational_unit_id' => $organizationalUnit->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['organizational_unit_id']);
});

test('scoped employee create accepts only effectively accessible establishments and assignable ranks', function (): void {
    $scopeUnit = OrganizationalUnit::factory()->forTenant((string) $this->tenant->id)->create();
    grantEmployeeEstablishmentAccess(
        $this->user,
        $this->tenant,
        $this->legalEntity,
        $this->establishment,
        $scopeUnit,
    );

    $this->withToken($this->token)
        ->postJson('/v1/employees', employeeDomainPayload([
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]))
        ->assertCreated();

    $hiddenEstablishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);

    $this->withToken($this->token)
        ->postJson('/v1/employees', employeeDomainPayload([
            'email' => 'hidden.employee@secpal.dev',
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $hiddenEstablishment->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['establishment_id']);

    $this->withToken($this->token)
        ->postJson('/v1/employees', employeeDomainPayload([
            'email' => 'rank.denied@secpal.dev',
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'management_level' => 1,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['management_level']);
});

test('non-current customer assignments no longer grant employee write access', function (string $deletedDomain): void {
    $scopeUnit = OrganizationalUnit::factory()->forTenant((string) $this->tenant->id)->create();
    grantEmployeeEstablishmentAccess(
        $this->user,
        $this->tenant,
        $this->legalEntity,
        $this->establishment,
        $scopeUnit,
    );

    $assignedCustomerId = $this->user->customerAssignments()->sole()->customer_id;
    $assignedCustomer = Customer::query()->findOrFail($assignedCustomerId);
    match ($deletedDomain) {
        'customer' => $assignedCustomer->delete(),
        'legal entity' => $assignedCustomer->legalEntity()->delete(),
    };

    $expectedValidationField = $deletedDomain === 'legal entity'
        ? 'legal_entity_id'
        : 'establishment_id';

    $this->withToken($this->token)
        ->postJson('/v1/employees', employeeDomainPayload([
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$expectedValidationField]);

    expect(Employee::query()->where('email', 'erika.musterfrau@secpal.dev')->exists())->toBeFalse();
})->with(['customer', 'legal entity']);

test('employee update validates the resulting pair and effective access', function (): void {
    $scopeUnit = OrganizationalUnit::factory()->forTenant((string) $this->tenant->id)->create();
    grantEmployeeEstablishmentAccess(
        $this->user,
        $this->tenant,
        $this->legalEntity,
        $this->establishment,
        $scopeUnit,
    );
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
        'management_level' => 0,
    ]);

    $otherLegalEntity = LegalEntity::factory()->forTenant((string) $this->tenant->id)->create();
    $hiddenEstablishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $otherLegalEntity->id,
    ]);

    $this->withToken($this->token)
        ->patchJson("/v1/employees/{$employee->id}", [
            'legal_entity_id' => $otherLegalEntity->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['establishment_id']);

    $this->withToken($this->token)
        ->patchJson("/v1/employees/{$employee->id}", [
            'legal_entity_id' => $otherLegalEntity->id,
            'establishment_id' => $hiddenEstablishment->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['establishment_id']);

    expect($employee->refresh()->legal_entity_id)->toBe($this->legalEntity->id)
        ->and($employee->establishment_id)->toBe($this->establishment->id);
});
