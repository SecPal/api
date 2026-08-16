<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\OrganizationalUnit;
use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\EmployeeQualificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property EmployeeQualificationPolicy $policy
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

    $this->policy = new EmployeeQualificationPolicy;
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('users with employee_qualification.read permission can view any employee qualifications', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.read');

    expect($this->policy->viewAny($user))->toBeTrue();
});

test('unscoped users with qualification permissions have tenant-wide access', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.read');
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.write');
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => Employee::factory()->for($this->tenant, 'tenant')->create()->id,
        'qualification_id' => Qualification::factory()->create()->id,
    ]);

    expect($this->policy->view($user, $employeeQualification))->toBeTrue()
        ->and($this->policy->update($user, $employeeQualification))->toBeTrue()
        ->and($this->policy->delete($user, $employeeQualification))->toBeTrue();
});

test('users with employee_qualification.read permission can view any employee qualifications (Manager)', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.read');

    expect($this->policy->viewAny($user))->toBeTrue();
});

test('employee can view own qualifications', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
    ]);
    $qualification = Qualification::factory()->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->view($user, $employeeQualification))->toBeTrue();
});

test('employee cannot view other employees qualifications', function (): void {
    $user = User::factory()->create();
    $otherEmployee = Employee::factory()->for($this->tenant, 'tenant')->create();
    $qualification = Qualification::factory()->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $otherEmployee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->view($user, $employeeQualification))->toBeFalse();
});

test('qualification policies reject employees from another tenant', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.read');
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.write');
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $employee = Employee::factory()->for($otherTenant, 'tenant')->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => Qualification::factory()->for($otherTenant, 'tenant')->create()->id,
    ]);

    expect($this->policy->viewAny($user, $employee))->toBeFalse()
        ->and($this->policy->view($user, $employeeQualification))->toBeFalse()
        ->and($this->policy->create($user, $employee))->toBeFalse()
        ->and($this->policy->update($user, $employeeQualification))->toBeFalse()
        ->and($this->policy->delete($user, $employeeQualification))->toBeFalse();
});

test('OU-scoped users fail closed when viewing employee qualifications', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.read');
    giveOrganizationalScope($user, $orgUnit);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $qualification = Qualification::factory()->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->view($user, $employeeQualification))->toBeFalse();
});

test('OU scopes do not grant employee qualification access', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.read');

    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $qualification = Qualification::factory()->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->view($user, $employeeQualification))->toBeFalse();
});

test('users with employee_qualification.read permission cannot view employee qualifications outside scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.read');

    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit1->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $qualification = Qualification::factory()->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->view($user, $employeeQualification))->toBeFalse();
});

test('only users with employee_qualification.write permission can create employee qualifications', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'employee_qualification.write');

    $userWithoutPermission = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->create($userWithPermission, $employee))->toBeTrue();
    expect($this->policy->create($userWithoutPermission, $employee))->toBeFalse();
});

test('OU-scoped users cannot create employee qualifications', function (): void {
    $organizationalUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create();

    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.write');
    giveOrganizationalScope($user, $organizationalUnit);

    expect($this->policy->create($user, $employee))->toBeFalse();
});

test('OU scopes do not grant employee qualification update access', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.write');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $qualification = Qualification::factory()->for($this->tenant, 'tenant')->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->update($user, $employeeQualification))->toBeFalse();
});

test('users with employee_qualification.write permission cannot update employee qualifications outside scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.write');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit1->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $qualification = Qualification::factory()->for($this->tenant, 'tenant')->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->update($user, $employeeQualification))->toBeFalse();
});

test('OU scopes do not grant employee qualification delete access', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.write');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $qualification = Qualification::factory()->for($this->tenant, 'tenant')->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->delete($user, $employeeQualification))->toBeFalse();
});

test('users with employee_qualification.write permission cannot delete employee qualifications outside scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.write');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit1->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);
    $qualification = Qualification::factory()->for($this->tenant, 'tenant')->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->delete($user, $employeeQualification))->toBeFalse();
});
