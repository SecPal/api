<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Employee;
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
    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');
    $this->token = $this->user->createToken('employee-domain-controller')->plainTextToken;
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

test('employee lists and details use effective establishment access and management ranges without OU fields', function (): void {
    $scopeUnit = OrganizationalUnit::factory()->forTenant((string) $this->tenant->id)->create();
    grantEmployeeEstablishmentAccess(
        $this->user,
        $this->tenant,
        $this->legalEntity,
        $this->establishment,
        $scopeUnit,
        maximumRank: 2,
    );
    $visibleEmployee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
        'management_level' => 2,
    ]);
    $rankHiddenEmployee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
        'management_level' => 3,
    ]);
    $hiddenEstablishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
    $domainHiddenEmployee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $hiddenEstablishment->id,
        'management_level' => 2,
    ]);

    $response = $this->withToken($this->token)->getJson('/v1/employees');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visibleEmployee->id)
        ->assertJsonPath('data.0.legal_entity_id', $this->legalEntity->id)
        ->assertJsonPath('data.0.establishment_id', $this->establishment->id)
        ->assertJsonMissingPath('data.0.organizational_unit_id')
        ->assertJsonMissingPath('data.0.organizational_unit');

    $this->withToken($this->token)
        ->getJson("/v1/employees/{$visibleEmployee->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.organizational_unit_id')
        ->assertJsonMissingPath('data.organizational_unit');
    $this->withToken($this->token)->getJson("/v1/employees/{$rankHiddenEmployee->id}")->assertForbidden();
    $this->withToken($this->token)->getJson("/v1/employees/{$domainHiddenEmployee->id}")->assertForbidden();
});

test('non-current customer assignments no longer grant employee visibility', function (string $deletedDomain): void {
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

    $assignedCustomerId = $this->user->customerAssignments()->sole()->customer_id;
    $assignedCustomer = Customer::query()->findOrFail($assignedCustomerId);
    match ($deletedDomain) {
        'customer' => $assignedCustomer->delete(),
        'legal entity' => $assignedCustomer->legalEntity()->delete(),
    };

    $this->withToken($this->token)
        ->getJson('/v1/employees')
        ->assertOk()
        ->assertJsonMissing(['id' => $employee->id]);
    $this->withToken($this->token)
        ->getJson("/v1/employees/{$employee->id}")
        ->assertForbidden();
})->with(['customer', 'legal entity']);

test('employee lists fail closed when existing scopes do not grant read access', function (): void {
    $scopeUnit = OrganizationalUnit::factory()->forTenant((string) $this->tenant->id)->create();
    grantEmployeeEstablishmentAccess(
        $this->user,
        $this->tenant,
        $this->legalEntity,
        $this->establishment,
        $scopeUnit,
    );
    $this->user->organizationalScopes()->update(['access_level' => 'none']);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
        'management_level' => 0,
    ]);

    $this->withToken($this->token)
        ->getJson('/v1/employees')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('legacy OU list filters are not part of employee filtering', function (): void {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
    ]);

    $this->withToken($this->token)
        ->getJson('/v1/employees?organizational_unit_id=00000000-0000-4000-8000-000000000000')
        ->assertOk()
        ->assertJsonPath('data.0.id', $employee->id)
        ->assertJsonMissingPath('data.0.organizational_unit_id');
});

test('an active site assignment grants employee visibility only while its customer domain is current', function (string $deletedDomain): void {
    $scopeUnit = OrganizationalUnit::factory()->forTenant((string) $this->tenant->id)->create();
    giveOrganizationalScope(
        $this->user,
        $scopeUnit,
        minViewableRank: 0,
        maxViewableRank: 0,
        minAssignableRank: 0,
        maxAssignableRank: 0,
        accessLevel: 'read',
    );
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
    CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'customer_id' => $customer->id,
        'establishment_id' => $this->establishment->id,
    ]);
    $site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
        'customer_id' => $customer->id,
    ]);
    SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $site->id,
        'user_id' => $this->user->id,
    ]);
    $visibleEmployee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
        'management_level' => 0,
    ]);
    $otherEstablishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
    $hiddenEmployee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $otherEstablishment->id,
        'management_level' => 0,
    ]);

    $this->withToken($this->token)
        ->getJson('/v1/employees')
        ->assertOk()
        ->assertJsonFragment(['id' => $visibleEmployee->id])
        ->assertJsonMissing(['id' => $hiddenEmployee->id]);

    match ($deletedDomain) {
        'customer' => $customer->delete(),
        'legal entity' => $customer->legalEntity()->delete(),
    };

    $this->withToken($this->token)
        ->getJson('/v1/employees')
        ->assertOk()
        ->assertJsonMissing(['id' => $visibleEmployee->id]);
})->with(['customer', 'legal entity']);

test('historical employees remain visible after their assigned establishment is closed', function (): void {
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
    $this->establishment->update(['is_active' => false]);
    $this->establishment->delete();

    $this->withToken($this->token)
        ->getJson('/v1/employees')
        ->assertOk()
        ->assertJsonFragment(['id' => $employee->id]);
    $this->withToken($this->token)
        ->getJson("/v1/employees/{$employee->id}")
        ->assertOk();
});

test('historical employees can be updated without reassigning their closed domain', function (): void {
    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.update');
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
    ]);
    $this->establishment->update(['is_active' => false]);

    $this->withToken($this->token)
        ->patchJson("/v1/employees/{$employee->id}", ['phone' => '+49 30 123456'])
        ->assertOk();

    expect($employee->refresh()->phone)->toBe('+49 30 123456');
});
