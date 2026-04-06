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

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property EmployeePolicy $policy
 * @property OrganizationalUnit $orgUnit
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

    // Create organizational unit
    $this->orgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    // Leadership ranks (1-5 for testing, 1=CEO, 5=lowest in test hierarchy)
    // No LeadershipLevel entities needed - just use integers
});

afterEach(function (): void {
    // Reset tenant context
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

// ============================================================================
// SELF-ACCESS CONTROL TESTS (NEW!)
// Issue #425: Prevent users from viewing/editing own HR data by default
// ============================================================================

test('user with allow_self_access=false cannot view own employee record', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 5, // FE5
    ]);

    // Create scope with allow_self_access = false (default)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'max_viewable_rank' => 255,
        'allow_self_access' => false, // Explicit: cannot view own data
    ]);

    // SHOULD FAIL: User cannot view own employee record
    expect($this->policy->view($user, $employee))->toBeFalse();
});

test('user with allow_self_access=true can view own employee record', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 5, // FE5
    ]);

    // Create scope with allow_self_access = true (HR Manager)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'max_viewable_rank' => 255,
        'allow_self_access' => true, // Explicit: can view own data
    ]);

    // SHOULD PASS: User can view own employee record
    expect($this->policy->view($user, $employee))->toBeTrue();
});

test('user with allow_self_access=false cannot edit own salary', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.update');

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'hourly_rate' => '15.50',
    ]);

    // Create scope with allow_self_access = false
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'max_viewable_rank' => 255,
        'allow_self_access' => false,
    ]);

    // SHOULD FAIL: User cannot edit own employee data
    expect($this->policy->update($user, $employee))->toBeFalse();
});

test('user with allow_self_access=true can edit own employee record', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.update');

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'organizational_unit_id' => $this->orgUnit->id,
    ]);

    // Create scope with allow_self_access = true
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'max_viewable_rank' => 255,
        'allow_self_access' => true,
    ]);

    // SHOULD PASS: User can edit own employee record
    expect($this->policy->update($user, $employee))->toBeTrue();
});

// ============================================================================
// USER'S OWN LEVEL IRRELEVANCE TESTS
// Issue #425: User's own management_level does NOT affect viewing permissions
// ============================================================================

test('user with FE5 and scope min=1 max=3 can see FE1-3 employees', function (): void {
    // User is FE5 (Objektleiter)
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // Note: User has FE5, but scope only allows viewing FE1-FE3
    // This tests that user's own leadership level does NOT affect viewing permissions

    // Scope says: can view FE1-FE3 (superiors!)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 3,
        'allow_self_access' => true, // Allow self-access for this test
    ]);

    // Create FE1 employee (Geschäftsführer - CEO)
    $ceoEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 1, // FE1
    ]);

    // SHOULD PASS: User's own FE5 does NOT matter, scope says FE1-3 visible
    expect($this->policy->view($user, $ceoEmployee))->toBeTrue();
});

test('user with FE1 and scope min=5 max=255 can see FE5+ employees', function (): void {
    // User is FE1 (Geschäftsführer - CEO)
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // Note: User has FE1, but scope only allows viewing FE5+
    // This tests that user's own leadership level does NOT affect viewing permissions

    // Scope says: can view FE5+ only (subordinates!)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 5,
        'max_viewable_rank' => 255,
        'allow_self_access' => true,
    ]);

    // Create FE5 employee (Objektleiter - Site Manager)
    $siteManagerEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 5, // FE5
    ]);

    // SHOULD PASS: User's own FE1 does NOT matter, scope says FE5+ visible
    expect($this->policy->view($user, $siteManagerEmployee))->toBeTrue();
});

test('user with null FE and scope min=1 max=255 can see all leadership', function (): void {
    // User has NO leadership level (e.g., HR Manager)
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // Note: User has NO leadership level, but can still view all FE1-FE255
    // This tests that user's own leadership level does NOT affect viewing permissions

    // Scope says: can view all leadership (FE1-FE255)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 255,
        'allow_self_access' => true,
    ]);

    // Create FE1 employee (Geschäftsführer)
    $ceoEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 1, // FE1
    ]);

    // SHOULD PASS: User's own null FE does NOT matter, scope says all leadership visible
    expect($this->policy->view($user, $ceoEmployee))->toBeTrue();
});

// ============================================================================
// NULL/0 SEMANTICS TESTS
// Issue #425: max_viewable_rank = null/0 means ONLY non-leadership employees
// ============================================================================

test('scope with max_viewable_rank=0 shows ONLY non-leadership employees', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // Scope with max_viewable_rank = 0 (ONLY non-leadership)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 0, // Guards (ADR-009: min=0 for Guards)
        'max_viewable_rank' => 0, // CRITICAL: ONLY non-leadership!
        'allow_self_access' => true,
    ]);

    // Create non-leadership employee (Guard)
    $guardEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 0, // NO FE (Guards)
    ]);

    // Create leadership employee (FE5)
    $leadershipEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 5, // FE5
    ]);

    // SHOULD PASS: Guard visible (max=0 means ONLY non-leadership)
    expect($this->policy->view($user, $guardEmployee))->toBeTrue();

    // SHOULD FAIL: FE5 NOT visible (max=0 excludes ALL leadership)
    expect($this->policy->view($user, $leadershipEmployee))->toBeFalse();
});

test('scope with max_viewable_rank=255 shows all leadership levels', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // Scope with max_viewable_rank = 255 (all leadership)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 255, // All leadership levels
        'allow_self_access' => true,
    ]);

    // Create FE1 employee
    $fe1Employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 1, // FE1
    ]);

    // Create FE5 employee
    $fe5Employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 5, // FE5
    ]);

    // SHOULD PASS: All leadership visible
    expect($this->policy->view($user, $fe1Employee))->toBeTrue();
    expect($this->policy->view($user, $fe5Employee))->toBeTrue();
});

test('user with TWO scopes can see both non-leadership and leadership', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // Scope 1: Non-leadership only
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 0, // Guards (ADR-009: min=0 for Guards)
        'max_viewable_rank' => 0, // Non-leadership
        'allow_self_access' => true,
    ]);

    // Scope 2: All leadership
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 255, // All leadership
        'allow_self_access' => true,
    ]);

    // Create non-leadership employee (Guard)
    $guardEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 0,
    ]);

    // Create leadership employee (FE5)
    $leadershipEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 5, // FE5
    ]);

    // SHOULD PASS: Both visible due to TWO scopes
    expect($this->policy->view($user, $guardEmployee))->toBeTrue();
    expect($this->policy->view($user, $leadershipEmployee))->toBeTrue();
});

// ============================================================================
// RANK RANGE TESTS
// Issue #425: min/max_viewable_rank filters
// ============================================================================

test('scope with min=4 max=6 shows only FE4-FE6', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 4,
        'max_viewable_rank' => 6,
        'allow_self_access' => true,
    ]);

    // Create FE3 employee (outside range)
    $fe3Employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 3, // FE3
    ]);

    // Create FE4 employee (in range)
    $fe4Employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 4, // FE4
    ]);

    // Create FE5 employee (in range)
    $fe5Employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 5, // FE5
    ]);

    // SHOULD FAIL: FE3 outside range (min=4)
    expect($this->policy->view($user, $fe3Employee))->toBeFalse();

    // SHOULD PASS: FE4 and FE5 in range
    expect($this->policy->view($user, $fe4Employee))->toBeTrue();
    expect($this->policy->view($user, $fe5Employee))->toBeTrue();
});

// ============================================================================
// NON-LEADERSHIP EMPLOYEE TESTS
// Issue #425: Guards (no FE) require explicit scope with max=0
// ============================================================================

test('guards NOT visible if user only has scope with min=1 max=255', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // Scope with min=1 max=255 (leadership only!)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 255,
        'allow_self_access' => true,
    ]);

    // Create non-leadership employee (Guard)
    $guardEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 0,
    ]);

    // SHOULD FAIL: Guard not visible (scope is leadership-only)
    expect($this->policy->view($user, $guardEmployee))->toBeFalse();
});
test('guards ARE visible if user has scope with min=0 max=0', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // Scope with min=0 max=0 (guards only!)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0,
        'allow_self_access' => true,
    ]);

    // Create Guard (no FE)
    $guardEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 0,
    ]);

    // SHOULD PASS: Guard visible with scope 0-0
    expect($this->policy->view($user, $guardEmployee))->toBeTrue();
});

test('user with TWO scopes (0-0 and 1-255) can see both guards and leadership', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

    // Scope 1: Guards (min=0 max=0)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0,
        'allow_self_access' => true,
    ]);

    // Scope 2: All Leadership (min=1 max=255)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 255,
        'allow_self_access' => true,
    ]);

    // Create Guard (no FE)
    $guardEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 0,
    ]);

    // Create Leadership FE50
    $leadershipEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'management_level' => 50,
    ]);

    // SHOULD PASS: Both visible
    expect($this->policy->view($user, $guardEmployee))->toBeTrue();
    expect($this->policy->view($user, $leadershipEmployee))->toBeTrue();
});
