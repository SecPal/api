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

test('users with Admin role can view any employee qualifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    expect($this->policy->viewAny($admin))->toBeTrue();
});

test('users with Manager role can view any employee qualifications', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    expect($this->policy->viewAny($manager))->toBeTrue();
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

test('users with Admin role can view all employee qualifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create();
    $qualification = Qualification::factory()->create();
    $employeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
    ]);

    expect($this->policy->view($admin, $employeeQualification))->toBeTrue();
});

test('users with Manager role can view employee qualifications in scope', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $manager->organizationalScopes()->create([
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

    expect($this->policy->view($manager, $employeeQualification))->toBeTrue();
});

test('users with Manager role cannot view employee qualifications outside scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $manager->organizationalScopes()->create([
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

    expect($this->policy->view($manager, $employeeQualification))->toBeFalse();
});

test('only users with Admin or Manager role can create employee qualifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $employee = User::factory()->create();

    expect($this->policy->create($admin))->toBeTrue();
    expect($this->policy->create($manager))->toBeTrue();
    expect($this->policy->create($employee))->toBeFalse();
});

test('only users with Admin or Manager role can update employee qualifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $orgUnit = OrganizationalUnit::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('Manager');
    $manager->organizationalScopes()->create([
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

    expect($this->policy->update($admin, $employeeQualification))->toBeTrue();
    expect($this->policy->update($manager, $employeeQualification))->toBeTrue();
});

test('users with Manager role cannot update employee qualifications outside scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('Manager');
    $manager->organizationalScopes()->create([
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

    expect($this->policy->update($manager, $employeeQualification))->toBeFalse();
});

test('only users with Admin or Manager role can delete employee qualifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $orgUnit = OrganizationalUnit::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('Manager');
    $manager->organizationalScopes()->create([
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

    expect($this->policy->delete($admin, $employeeQualification))->toBeTrue();
    expect($this->policy->delete($manager, $employeeQualification))->toBeTrue();
});

test('users with Manager role cannot delete employee qualifications outside scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('Manager');
    $manager->organizationalScopes()->create([
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

    expect($this->policy->delete($manager, $employeeQualification))->toBeFalse();
});
