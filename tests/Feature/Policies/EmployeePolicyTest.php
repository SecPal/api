<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\EmployeePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system (required for role assignments)
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->policy = new EmployeePolicy;
});

afterEach(function (): void {
    // Reset tenant context
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('admin can view any employees', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    expect($this->policy->viewAny($admin))->toBeTrue();
});

test('manager can view any employees', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    expect($this->policy->viewAny($manager))->toBeTrue();
});

test('regular employee cannot view any employees', function (): void {
    $employee = User::factory()->create();

    expect($this->policy->viewAny($employee))->toBeFalse();
});

test('employee can view own profile', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
    ]);

    expect($this->policy->view($user, $employee))->toBeTrue();
});

test('employee cannot view other employees', function (): void {
    $user = User::factory()->create();
    $otherEmployee = Employee::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->view($user, $otherEmployee))->toBeFalse();
});

test('admin can view all employees', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->view($admin, $employee))->toBeTrue();
});

test('manager can view employees in own organizational scope', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    // Add organizational scope for manager
    $manager->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
    ]);

    expect($this->policy->view($manager, $employee))->toBeTrue();
});

test('manager cannot view employees outside organizational scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    // Manager has scope for orgUnit1 only
    $manager->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit1->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    // Employee is in orgUnit2
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit2->id,
    ]);

    expect($this->policy->view($manager, $employee))->toBeFalse();
});

test('only admin can create employees', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $employee = User::factory()->create();

    expect($this->policy->create($admin))->toBeTrue();
    expect($this->policy->create($manager))->toBeFalse();
    expect($this->policy->create($employee))->toBeFalse();
});

test('employee can update own profile', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
    ]);

    expect($this->policy->update($user, $employee))->toBeTrue();
});

test('admin can update all employees', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->update($admin, $employee))->toBeTrue();
});

test('employee cannot update other employees', function (): void {
    $user = User::factory()->create();
    $otherEmployee = Employee::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->update($user, $otherEmployee))->toBeFalse();
});

test('only admin can delete employees', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->delete($admin, $employee))->toBeTrue();
    expect($this->policy->delete($manager, $employee))->toBeFalse();
});

test('only admin can activate employees', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => 'pre_contract',
    ]);

    expect($this->policy->activate($admin, $employee))->toBeTrue();
    expect($this->policy->activate($manager, $employee))->toBeFalse();
});

test('only admin can terminate employees', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => 'active',
    ]);

    expect($this->policy->terminate($admin, $employee))->toBeTrue();
    expect($this->policy->terminate($manager, $employee))->toBeFalse();
});
