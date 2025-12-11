<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\EmployeeDocumentPolicy;
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

    $this->policy = new EmployeeDocumentPolicy;
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('admin can view any employee documents', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    expect($this->policy->viewAny($admin))->toBeTrue();
});

test('manager can view any employee documents', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    expect($this->policy->viewAny($manager))->toBeTrue();
});

test('regular employee cannot view any documents', function (): void {
    $employee = User::factory()->create();

    expect($this->policy->viewAny($employee))->toBeFalse();
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

test('admin can view all documents regardless of visibility flag', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create();
    $document = EmployeeDocument::factory()->for($employee)->create([
        'visible_to_employee' => false,
    ]);

    expect($this->policy->view($admin, $document))->toBeTrue();
});

test('manager can view documents of employees in scope', function (): void {
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
    $document = EmployeeDocument::factory()->for($employee)->create([
        'visible_to_employee' => false,
    ]);

    expect($this->policy->view($manager, $document))->toBeTrue();
});

test('manager cannot view documents of employees outside scope', function (): void {
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
    $document = EmployeeDocument::factory()->for($employee)->create([
        'visible_to_employee' => false,
    ]);

    expect($this->policy->view($manager, $document))->toBeFalse();
});

test('only admin and managers can create documents', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $employee = User::factory()->create();

    expect($this->policy->create($admin))->toBeTrue();
    expect($this->policy->create($manager))->toBeTrue();
    expect($this->policy->create($employee))->toBeFalse();
});

test('only admin and managers can update documents', function (): void {
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
    $document = EmployeeDocument::factory()->for($employee)->create();

    expect($this->policy->update($admin, $document))->toBeTrue();
    expect($this->policy->update($manager, $document))->toBeTrue();
});

test('regular employee cannot update documents', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
    ]);
    $document = EmployeeDocument::factory()->for($employee)->create();

    expect($this->policy->update($user, $document))->toBeFalse();
});

test('manager cannot update documents outside scope', function (): void {
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
    $document = EmployeeDocument::factory()->for($employee)->create();

    expect($this->policy->update($manager, $document))->toBeFalse();
});

test('only admin and managers can delete documents', function (): void {
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
    $document = EmployeeDocument::factory()->for($employee)->create();

    expect($this->policy->delete($admin, $document))->toBeTrue();
    expect($this->policy->delete($manager, $document))->toBeTrue();
});

test('manager cannot delete documents outside scope', function (): void {
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
    $document = EmployeeDocument::factory()->for($employee)->create();

    expect($this->policy->delete($manager, $document))->toBeFalse();
});
