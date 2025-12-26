<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
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

uses(RefreshDatabase::class);

beforeEach(function (): void {
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

test('users with employee_qualification.read permission can view all employee qualifications', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.read');
    giveOrganizationalScope($user, $orgUnit);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
    ]);
    $qualification = Qualification::factory()->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->view($user, $employeeQualification))->toBeTrue();
});

test('users with employee_qualification.read permission can view employee qualifications in scope', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.read');

    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
    ]);
    $qualification = Qualification::factory()->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->view($user, $employeeQualification))->toBeTrue();
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
        'organizational_unit_id' => $orgUnit2->id,
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

    expect($this->policy->create($userWithPermission))->toBeTrue();
    expect($this->policy->create($userWithoutPermission))->toBeFalse();
});

test('users with employee_qualification.write permission can update employee qualifications in scope', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.write');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
    ]);
    $qualification = Qualification::factory()->for($this->tenant, 'tenant')->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->update($user, $employeeQualification))->toBeTrue();
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
        'organizational_unit_id' => $orgUnit2->id,
    ]);
    $qualification = Qualification::factory()->for($this->tenant, 'tenant')->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->update($user, $employeeQualification))->toBeFalse();
});

test('users with employee_qualification.write permission can delete employee qualifications in scope', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee_qualification.write');
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
    ]);
    $qualification = Qualification::factory()->for($this->tenant, 'tenant')->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->delete($user, $employeeQualification))->toBeTrue();
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
        'organizational_unit_id' => $orgUnit2->id,
    ]);
    $qualification = Qualification::factory()->for($this->tenant, 'tenant')->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->delete($user, $employeeQualification))->toBeFalse();
});
