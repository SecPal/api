<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use App\Policies\EmployeePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property EmployeePolicy $policy
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
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

test('users with employee.read permission can view any employees', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    expect($this->policy->viewAny($user))->toBeTrue();
});

test('users with employee.read permission can view any employees (Manager)', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    expect($this->policy->viewAny($user))->toBeTrue();
});

test('users without employee.read permission cannot view any employees', function (): void {
    $user = User::factory()->create();

    expect($this->policy->viewAny($user))->toBeFalse();
});

test('employee can view own profile', function (): void {
    // NOTE: Self-access control (ADR-009) requires allow_self_access = true in scope
    // This test was updated to reflect the new architecture where self-access is DISABLED by default
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    $orgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'organizational_unit_id' => $orgUnit->id,
    ]);

    // Create scope with allow_self_access = true
    UserInternalOrganizationalScope::create([
        'user_id' => $user->id,
        'organizational_unit_id' => $orgUnit->id,
        'access_level' => 'write',
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0,
        'allow_self_access' => true, // Required for self-access
    ]);

    expect($this->policy->view($user, $employee))->toBeTrue();
});

test('employee cannot view other employees', function (): void {
    $user = User::factory()->create();
    $otherEmployee = Employee::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->view($user, $otherEmployee))->toBeFalse();
});

test('users with employee.read permission can view all employees', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // Need TWO scopes: 0-0 for Guards + 1-255 for Leadership (ADR-009)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'access_level' => 'manage',
        'include_descendants' => true,
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0, // Guards only
        'allow_self_access' => true,
    ]);
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'access_level' => 'manage',
        'include_descendants' => true,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 255, // Leadership only
        'allow_self_access' => true,
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
    ]);

    expect($this->policy->view($user, $employee))->toBeTrue();
});

test('users with employee.read permission can view employees in own organizational scope', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // Need TWO scopes to see all employees: 0-0 for Guards + 1-255 for Leadership
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0, // Guards only
        'allow_self_access' => true,
    ]);
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 255, // Leadership only
        'allow_self_access' => true,
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
    ]);

    expect($this->policy->view($user, $employee))->toBeTrue();
});

test('users with employee.read permission cannot view employees outside organizational scope', function (): void {
    $orgUnit1 = OrganizationalUnit::factory()->create();
    $orgUnit2 = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // User has scope for orgUnit1 only
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit1->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    // Employee is in orgUnit2
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit2->id,
    ]);

    expect($this->policy->view($user, $employee))->toBeFalse();
});

test('only users with employee.write or employee.create permission can create employees', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'employee.write');

    $userWithoutPermission = User::factory()->create();

    expect($this->policy->create($userWithPermission))->toBeTrue();
    expect($this->policy->create($userWithoutPermission))->toBeFalse();
});

test('employee can update own profile', function (): void {
    // NOTE: Self-access control (ADR-009) requires allow_self_access = true in scope
    // This test was updated to reflect the new architecture where self-access is DISABLED by default
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.update');

    $orgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'organizational_unit_id' => $orgUnit->id,
    ]);

    // Create scope with allow_self_access = true
    UserInternalOrganizationalScope::create([
        'user_id' => $user->id,
        'organizational_unit_id' => $orgUnit->id,
        'access_level' => 'write',
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0,
        'allow_self_access' => true, // Required for self-access
    ]);

    expect($this->policy->update($user, $employee))->toBeTrue();
});

test('users with employee.write permission can update all employees', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'employee.write');

    // Need TWO scopes to update all employees: 0-0 for Guards + 1-255 for Leadership
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'access_level' => 'manage',
        'include_descendants' => true,
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0, // Guards only
        'allow_self_access' => true,
    ]);
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'access_level' => 'manage',
        'include_descendants' => true,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 255, // Leadership only
        'allow_self_access' => true,
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
    ]);

    expect($this->policy->update($user, $employee))->toBeTrue();
});

test('employee cannot update other employees', function (): void {
    $user = User::factory()->create();
    $otherEmployee = Employee::factory()->for($this->tenant, 'tenant')->create();

    expect($this->policy->update($user, $otherEmployee))->toBeFalse();
});

test('only users with employee.write or employee.delete permission can delete employees', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $userWithPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'employee.write');
    giveOrganizationalScope($userWithPermission, $orgUnit, 0, 0, 0, 0);

    $userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
        'management_level' => 0,
    ]);

    expect($this->policy->delete($userWithPermission, $employee))->toBeTrue();
    expect($this->policy->delete($userWithoutPermission, $employee))->toBeFalse();
});

test('only users with employee.write or employee.activate permission can activate employees', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $userWithPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'employee.write');
    giveOrganizationalScope($userWithPermission, $orgUnit, 0, 0, 0, 0);

    $userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
        'management_level' => 0,
        'status' => 'pre_contract',
    ]);

    expect($this->policy->activate($userWithPermission, $employee))->toBeTrue();
    expect($this->policy->activate($userWithoutPermission, $employee))->toBeFalse();
});

test('only users with employee.write or employee.terminate permission can terminate employees', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $userWithPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'employee.write');
    giveOrganizationalScope($userWithPermission, $orgUnit, 0, 0, 0, 0);

    $userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
        'management_level' => 0,
        'status' => 'active',
    ]);

    expect($this->policy->terminate($userWithPermission, $employee))->toBeTrue();
    expect($this->policy->terminate($userWithoutPermission, $employee))->toBeFalse();
});

test('delete activate and terminate require the same scope and rank coverage as update', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.delete');
    givePermissionWithTenant($user, $this->tenant->id, 'employee.activate');
    givePermissionWithTenant($user, $this->tenant->id, 'employee.terminate');
    givePermissionWithTenant($user, $this->tenant->id, 'employee.update');

    $orgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0,
        'min_assignable_rank' => 0,
        'max_assignable_rank' => 0,
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
        'management_level' => 4,
        'status' => Employee::STATUS_ACTIVE,
    ]);

    expect($this->policy->update($user, $employee))->toBeFalse()
        ->and($this->policy->delete($user, $employee))->toBeFalse()
        ->and($this->policy->activate($user, $employee))->toBeFalse()
        ->and($this->policy->terminate($user, $employee))->toBeFalse();
});

test('self lifecycle actions still require assignable rank coverage', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');
    givePermissionWithTenant($user, $this->tenant->id, 'employee.update');
    givePermissionWithTenant($user, $this->tenant->id, 'employee.delete');
    givePermissionWithTenant($user, $this->tenant->id, 'employee.activate');
    givePermissionWithTenant($user, $this->tenant->id, 'employee.terminate');
    givePermissionWithTenant($user, $this->tenant->id, 'employee.write');

    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'organizational_unit_id' => $orgUnit->id,
        'management_level' => 5,
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $user->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 5,
        'min_assignable_rank' => 1,
        'max_assignable_rank' => 3,
        'allow_self_access' => true,
    ]);

    expect($this->policy->view($user, $employee))->toBeTrue()
        ->and($this->policy->update($user, $employee))->toBeTrue()
        ->and($this->policy->delete($user, $employee))->toBeFalse()
        ->and($this->policy->activate($user, $employee))->toBeFalse()
        ->and($this->policy->placeOnLeave($user, $employee))->toBeFalse()
        ->and($this->policy->returnFromLeave($user, $employee))->toBeFalse()
        ->and($this->policy->terminate($user, $employee))->toBeFalse();
});

test('activate denies users whose permissions do not match the requested action', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.delete');
    giveOrganizationalScope($user, $orgUnit, 0, 0, 0, 0);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
        'management_level' => 0,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    expect($this->policy->activate($user, $employee))->toBeFalse();
});

test('lifecycle actions remain authorized for employees in soft-deleted organizational units', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.delete');
    givePermissionWithTenant($user, $this->tenant->id, 'employee.activate');
    givePermissionWithTenant($user, $this->tenant->id, 'employee.terminate');

    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    giveOrganizationalScope($user, $orgUnit, 0, 0, 0, 0);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
        'management_level' => 0,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $orgUnit->delete();
    $employee->refresh();

    expect($employee->organizationalUnit)->toBeNull()
        ->and($this->policy->delete($user, $employee))->toBeTrue()
        ->and($this->policy->activate($user, $employee))->toBeTrue()
        ->and($this->policy->terminate($user, $employee))->toBeTrue();
});

test('only users with employee.write permission can place employees on leave', function (): void {
    $userWithPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'employee.write');
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    giveOrganizationalScope($userWithPermission, $orgUnit, 0, 0, 0, 0);

    $userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
        'management_level' => 0,
        'status' => Employee::STATUS_ACTIVE,
    ]);

    expect($this->policy->placeOnLeave($userWithPermission, $employee))->toBeTrue();
    expect($this->policy->placeOnLeave($userWithoutPermission, $employee))->toBeFalse();
});

test('only users with employee.write permission can return employees from leave', function (): void {
    $userWithPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'employee.write');
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    giveOrganizationalScope($userWithPermission, $orgUnit, 0, 0, 0, 0);

    $userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
        'management_level' => 0,
        'status' => Employee::STATUS_ON_LEAVE,
    ]);

    expect($this->policy->returnFromLeave($userWithPermission, $employee))->toBeTrue();
    expect($this->policy->returnFromLeave($userWithoutPermission, $employee))->toBeFalse();
});
