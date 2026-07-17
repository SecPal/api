<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Activity;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\ActivityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

// Note: Tests marked as 'serial' to avoid PostgreSQL deadlocks with parallel execution
// ParaTest may still run these in parallel locally, but CI runs them properly
uses(RefreshDatabase::class)->group('serial');

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property ActivityPolicy $policy
 * @property OrganizationalUnit $orgUnit
 */
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

    $this->policy = new ActivityPolicy;

    // Create organizational unit
    $this->orgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
});

afterEach(function (): void {
    // Reset tenant context
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * Helper function to create an employee with associated user in one call.
 * Reduces repetitive code pattern throughout tests.
 */
function createEmployeeWithUser(TenantKey $tenant, OrganizationalUnit $orgUnit, ?int $leadershipRank = null): array
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->for($tenant, 'tenant')->create([
        'management_level' => $leadershipRank,
        'user_id' => $user->id,
    ]);

    return ['user' => $user, 'employee' => $employee];
}

// ============================================================================
// viewAny() TESTS
// ============================================================================

test('viewAny allows users with activity_log.read permission', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    expect($this->policy->viewAny($user))->toBeTrue();
});

test('viewAny denies users without activity_log.read permission', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($this->policy->viewAny($user))->toBeFalse();
});

// ============================================================================
// view() BASIC TESTS - Tenant Isolation
// ============================================================================

test('view denies activity from different tenant', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // Create another tenant
    $otherTenantKey = TenantKey::factory()->create();
    $activity = Activity::factory()->create([
        'tenant_id' => $otherTenantKey->id,
        'organizational_unit_id' => null,
    ]);

    expect($this->policy->view($user, $activity))->toBeFalse();
});

test('view allows global activity for users with activity_log.read permission', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => null, // Global activity
        'causer_id' => null,
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});

test('view denies global activity for users without activity_log.read permission', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => null,
    ]);

    expect($this->policy->view($user, $activity))->toBeFalse();
});

// ============================================================================
// view() ORGANIZATIONAL SCOPE TESTS
// ============================================================================

test('view denies activity from organizational unit without user scope', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_id' => null,
    ]);

    expect($this->policy->view($user, $activity))->toBeFalse();
});

test('view allows activity from organizational unit with user scope', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'max_viewable_rank' => 255, // All leadership
    ]);

    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_id' => null,
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});

// ============================================================================
// view() LEADERSHIP LEVEL FILTERING TESTS - Issue #396
// ============================================================================

test('view allows activity caused by guard (no leadership level) when scope includes min_viewable_rank=0', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // Create guard employee (no leadership level)
    ['user' => $guardUser, 'employee' => $guardEmployee] = createEmployeeWithUser($this->tenant, $this->orgUnit, 0);

    // User scope: Guards only (min=0, max=0)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 0, // Includes Guards
        'max_viewable_rank' => 0, // Guards only
    ]);

    // Activity caused by guard
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $guardUser->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});

test('view denies activity caused by guard when scope excludes guards (min=1)', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // Create guard employee
    ['user' => $guardUser, 'employee' => $guardEmployee] = createEmployeeWithUser($this->tenant, $this->orgUnit, 0);

    // User scope: Leadership only (min=1, max=255)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 1, // FE1+ only
        'max_viewable_rank' => 255,
    ]);

    // Activity caused by guard
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $guardUser->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeFalse();
});

test('view allows activity caused by leadership within viewable rank range', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // Create FE3 employee
    ['user' => $fe3User, 'employee' => $fe3Employee] = createEmployeeWithUser($this->tenant, $this->orgUnit, 3);

    // User scope: FE1-FE5
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 5,
    ]);

    // Activity caused by FE3
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $fe3User->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});

test('view denies activity caused by leadership below min_viewable_rank', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // Create FE1 employee (CEO)
    ['user' => $fe1User, 'employee' => $fe1Employee] = createEmployeeWithUser($this->tenant, $this->orgUnit, 1);

    // User scope: FE3-FE5 only (lower management)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 3,
        'max_viewable_rank' => 5,
    ]);

    // Activity caused by FE1 (CEO)
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $fe1User->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeFalse();
});

test('view denies activity caused by leadership above max_viewable_rank', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // Create FE5 employee
    ['user' => $fe5User, 'employee' => $fe5Employee] = createEmployeeWithUser($this->tenant, $this->orgUnit, 5);

    // User scope: FE1-FE3 only (upper management)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 3,
    ]);

    // Activity caused by FE5
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $fe5User->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeFalse();
});

test('view allows activity without causer (system-generated) regardless of rank filters', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // User scope: Guards only (min=0, max=0)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0,
    ]);

    // Activity without causer (system-generated)
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_id' => null, // System activity
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});

test('view allows activity with non-User causer (e.g., Employee) regardless of rank filters', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // User scope: Guards only
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0,
    ]);

    // Activity caused by Employee model (not User)
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
    ]);

    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => Employee::class,
        'causer_id' => $employee->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});

test('view allows activity when user has multiple scopes and one matches rank', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // Create FE3 employee
    $fe3Employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'management_level' => 3, // FE3
    ]);
    $fe3User = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $fe3Employee->user_id = $fe3User->id;
    $fe3Employee->save();

    // Scope 1: Guards only (won't match FE3)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0,
    ]);

    // Scope 2: FE1-FE5 (will match FE3)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 5,
    ]);

    // Activity caused by FE3
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $fe3User->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});

test('view allows activity caused by leadership when scope has no rank restrictions (null min/max)', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // Create FE3 employee
    $fe3Employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'management_level' => 3, // FE3
    ]);
    $fe3User = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $fe3Employee->user_id = $fe3User->id;
    $fe3Employee->save();

    // User scope: No rank restrictions (NULL = all ranks visible)
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => null, // No minimum filter
        'max_viewable_rank' => null, // No maximum filter
    ]);

    // Activity caused by FE3
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $fe3User->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});

test('view allows activity caused by guard when scope has no rank restrictions (null min/max)', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // Create guard employee (no leadership level)
    $guardEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'management_level' => 0, // Guard
    ]);
    $guardUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $guardEmployee->user_id = $guardUser->id;
    $guardEmployee->save();

    // User scope: No leadership rank restrictions (NULL = no rank filter)
    // CRITICAL: Guards (rank=null) still require explicit min_viewable_rank=0
    // NULL min/max only applies to leadership ranks (1-255), not guards
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => null, // No minimum leadership rank filter (does not include guards)
        'max_viewable_rank' => null, // No maximum leadership rank filter
    ]);

    // Activity caused by guard
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $guardUser->id,
    ]);

    // Guards require explicit min_viewable_rank=0, so this should be FALSE
    // NULL min does NOT grant guard access (by design in ADR-009)
    expect($this->policy->view($user, $activity))->toBeFalse();
});

// ============================================================================
// view() EDGE CASES
// ============================================================================

test('view allows activity when user views their OWN activity without employee record', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // User scope for org unit
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 5,
    ]);

    // User causes activity but has no employee record (e.g., a privileged service login)
    // This should be ALLOWED - users can always view their own activities
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $user->id, // Same user
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});

test('view allows own global privileged activity without read_system permission', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => null,
        'causer_type' => User::class,
        'causer_id' => $user->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});

test('view allows activity when OTHER user without employee record caused it (system users)', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read_system');

    // User scope: FE1-FE5
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 5,
    ]);

    // Causer without employee record (orphaned user, privileged service user, or system user)
    $causerUser = User::factory()->create(['tenant_id' => $this->tenant->id]);

    // Activity caused by user without employee record
    // This should be ALLOWED - users with activity_log.read_system permission can see system user activities
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $causerUser->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});

test('view denies activity when OTHER user without employee record caused it and viewer lacks read_system permission', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');
    // Note: NOT giving activity_log.read_system permission

    // User scope: FE1-FE5
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 5,
    ]);

    // Causer without employee record (system user)
    $causerUser = User::factory()->create(['tenant_id' => $this->tenant->id]);

    // Activity caused by system user
    // This should be DENIED - user lacks activity_log.read_system permission
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $causerUser->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeFalse();
});

test('view does not infer an OU association from the causer employee', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($user, $this->tenant->id, 'activity_log.read');

    // Other organizational unit
    $otherOrgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    // User scope for original org unit only
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $this->orgUnit->id,
        'access_level' => 'read',
        'include_descendants' => false,
        'max_viewable_rank' => 255,
    ]);

    // Causer from different org unit
    $otherEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'management_level' => 1,
    ]);
    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherEmployee->user_id = $otherUser->id;
    $otherEmployee->save();

    // Activity in original org unit, but caused by user from other org unit
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $otherUser->id,
    ]);

    expect($this->policy->view($user, $activity))->toBeTrue();
});
