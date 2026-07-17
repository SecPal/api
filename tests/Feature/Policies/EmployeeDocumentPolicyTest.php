<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\EmployeeDocumentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property EmployeeDocumentPolicy $policy
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->policy = new EmployeeDocumentPolicy;
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('users with employee_document.read permission can view any employee documents', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.read');

    expect($this->policy->viewAny($user, $employee))->toBeTrue();
});

test('OU-scoped users fail closed when listing employee documents', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $user = User::factory()->create();
    $user->assignRole('Manager');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'read',
    ]);
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.read');

    expect($this->policy->viewAny($user, $employee))->toBeFalse();
});

test('users without employee_document.read permission cannot view any documents', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->viewAny($user, $employee))->toBeFalse();
});

test('document policies reject employees from another tenant', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.read');
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.write');
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $employee = Employee::factory()->for($otherTenant, 'tenant')->create();
    $document = EmployeeDocument::factory()->for($employee)->create();

    expect($this->policy->viewAny($user, $employee))->toBeFalse()
        ->and($this->policy->view($user, $document))->toBeFalse()
        ->and($this->policy->create($user, $employee))->toBeFalse()
        ->and($this->policy->update($user, $document))->toBeFalse()
        ->and($this->policy->delete($user, $document))->toBeFalse()
        ->and($this->policy->download($user, $document))->toBeFalse();
});

test('employee can list own documents without explicit document permission', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
    ]);

    expect($this->policy->viewAny($user, $employee))->toBeTrue();
});

test('employee can view own documents marked visible to employee', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
    ]);
    $document = EmployeeDocument::factory()->for($employee)->create([
        'visible_to_employee' => true,
    ]);

    expect($this->policy->view($user, $document))->toBeTrue();
});

test('employee cannot view own documents marked not visible to employee', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
    ]);
    $document = EmployeeDocument::factory()->for($employee)->create([
        'visible_to_employee' => false,
    ]);

    expect($this->policy->view($user, $document))->toBeFalse();
});

test('OU-scoped users fail closed when viewing employee documents', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.read');
    giveOrganizationalScope($user, $orgUnit);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $document = EmployeeDocument::factory()->for($employee)->create([
        'visible_to_employee' => false,
    ]);

    expect($this->policy->view($user, $document))->toBeFalse();
});

test('OU scopes do not grant employee document access', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.read');

    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $document = EmployeeDocument::factory()->for($employee)->create([
        'visible_to_employee' => false,
    ]);

    expect($this->policy->view($user, $document))->toBeFalse();
});

test('users with employee_document.read permission cannot view documents of employees outside scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.read');

    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit1->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $document = EmployeeDocument::factory()->for($employee)->create([
        'visible_to_employee' => false,
    ]);

    expect($this->policy->view($user, $document))->toBeFalse();
});

test('only users with employee_document.write permission can create documents', function (): void {
    $userWithPermission = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'employee_document.write');

    $userWithoutPermission = User::factory()->create();

    expect($this->policy->create($userWithPermission, $employee))->toBeTrue();
    expect($this->policy->create($userWithoutPermission, $employee))->toBeFalse();
});

test('users with employee_document.write permission cannot create documents outside scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.write');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit1->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    expect($this->policy->create($user, $employee))->toBeFalse();
});

test('OU scopes do not grant employee document update access', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.write');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $document = EmployeeDocument::factory()->for($employee)->create();

    expect($this->policy->update($user, $document))->toBeFalse();
});

test('users without employee_document.write permission cannot update documents', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
    ]);
    $document = EmployeeDocument::factory()->for($employee)->create();

    expect($this->policy->update($user, $document))->toBeFalse();
});

test('users with employee_document.write permission cannot update documents outside scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.write');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit1->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $document = EmployeeDocument::factory()->for($employee)->create();

    expect($this->policy->update($user, $document))->toBeFalse();
});

test('OU scopes do not grant employee document delete access', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.write');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $document = EmployeeDocument::factory()->for($employee)->create();

    expect($this->policy->delete($user, $document))->toBeFalse();
});

test('users with employee_document.write permission cannot delete documents outside scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_document.write');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit1->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $document = EmployeeDocument::factory()->for($employee)->create();

    expect($this->policy->delete($user, $document))->toBeFalse();
});
