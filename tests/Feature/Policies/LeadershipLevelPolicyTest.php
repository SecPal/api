<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\LeadershipLevel;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\LeadershipLevelPolicy;
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

    $this->policy = new LeadershipLevelPolicy;
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * Tests for LeadershipLevelPolicy.
 *
 * CRITICAL: Per ADR-009, this policy uses PURE permission-based authorization.
 * User's OWN leadership level has ZERO influence on these checks.
 *
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/424 Issue #424: Leadership Levels Backend API
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251221-inheritance-blocking-and-leadership-access-control.md ADR-009
 */

// ========================================================================
// viewAny() Tests
// ========================================================================

test('users with leadership_level.view permission can view any leadership levels', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.view');

    expect($this->policy->viewAny($user))->toBeTrue();
});

test('users without leadership_level.view permission cannot view any leadership levels', function (): void {
    $user = User::factory()->create();

    expect($this->policy->viewAny($user))->toBeFalse();
});

test('viewAny ignores user own leadership level (pure permission check)', function (): void {
    $user = User::factory()->create();

    // Create employee with LOW rank leadership level (rank 10 = low authority)
    $lowLevel = LeadershipLevel::factory()->create([
        'tenant_id' => $this->tenant->id,
        'rank' => 10,
        'name' => 'Junior Staff',
    ]);

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $user->id,
        'leadership_level_id' => $lowLevel->id,
    ]);

    // User with LOW rank but WITH permission → can view
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.view');
    expect($this->policy->viewAny($user))->toBeTrue();

    // User with LOW rank but WITHOUT permission → cannot view
    $user->revokePermissionTo('leadership_level.view', 'sanctum');
    expect($this->policy->viewAny($user))->toBeFalse();
});

// ========================================================================
// view() Tests
// ========================================================================

test('users with leadership_level.view permission can view specific leadership level', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.view');

    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($this->policy->view($user, $leadershipLevel))->toBeTrue();
});

test('users without leadership_level.view permission cannot view specific leadership level', function (): void {
    $user = User::factory()->create();
    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($this->policy->view($user, $leadershipLevel))->toBeFalse();
});

test('view ignores user own leadership level (pure permission check)', function (): void {
    $user = User::factory()->create();

    // Create HIGH rank level (rank 1 = CEO)
    $highLevel = LeadershipLevel::factory()->create([
        'tenant_id' => $this->tenant->id,
        'rank' => 1,
        'name' => 'CEO',
    ]);

    // User tries to view HIGH rank level
    // WITHOUT permission → cannot view (even though viewing high level)
    expect($this->policy->view($user, $highLevel))->toBeFalse();

    // WITH permission → can view (regardless of level rank)
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.view');
    expect($this->policy->view($user, $highLevel))->toBeTrue();
});

// ========================================================================
// create() Tests
// ========================================================================

test('users with leadership_level.create permission can create leadership levels', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.create');

    expect($this->policy->create($user))->toBeTrue();
});

test('users without leadership_level.create permission cannot create leadership levels', function (): void {
    $user = User::factory()->create();

    expect($this->policy->create($user))->toBeFalse();
});

test('create ignores user own leadership level (pure permission check)', function (): void {
    $user = User::factory()->create();

    // Create LOW rank employee (rank 10 = low authority)
    $lowLevel = LeadershipLevel::factory()->create([
        'tenant_id' => $this->tenant->id,
        'rank' => 10,
        'name' => 'Junior Staff',
    ]);

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $user->id,
        'leadership_level_id' => $lowLevel->id,
    ]);

    // User with LOW rank but WITH permission → can create
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.create');
    expect($this->policy->create($user))->toBeTrue();

    // User with LOW rank but WITHOUT permission → cannot create
    $user->revokePermissionTo('leadership_level.create', 'sanctum');
    expect($this->policy->create($user))->toBeFalse();
});

// ========================================================================
// update() Tests
// ========================================================================

test('users with leadership_level.update permission can update leadership levels', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.update');

    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($this->policy->update($user, $leadershipLevel))->toBeTrue();
});

test('users without leadership_level.update permission cannot update leadership levels', function (): void {
    $user = User::factory()->create();
    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($this->policy->update($user, $leadershipLevel))->toBeFalse();
});

test('update ignores user own leadership level (pure permission check)', function (): void {
    $user = User::factory()->create();

    // Create HIGH rank level to update (rank 1 = CEO)
    $highLevel = LeadershipLevel::factory()->create([
        'tenant_id' => $this->tenant->id,
        'rank' => 1,
        'name' => 'CEO',
    ]);

    // User tries to update HIGH rank level
    // WITHOUT permission → cannot update (even though user might be low rank)
    expect($this->policy->update($user, $highLevel))->toBeFalse();

    // WITH permission → can update (regardless of level rank)
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.update');
    expect($this->policy->update($user, $highLevel))->toBeTrue();
});

// ========================================================================
// delete() Tests
// ========================================================================

test('users with leadership_level.delete permission can delete empty leadership levels', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.delete');

    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($this->policy->delete($user, $leadershipLevel))->toBeTrue();
});

test('users without leadership_level.delete permission cannot delete leadership levels', function (): void {
    $user = User::factory()->create();
    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($this->policy->delete($user, $leadershipLevel))->toBeFalse();
});

test('users cannot delete leadership levels with assigned employees', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.delete');

    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

    // Create employee assigned to this level
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'leadership_level_id' => $leadershipLevel->id,
    ]);

    expect($this->policy->delete($user, $leadershipLevel))->toBeFalse();
});

test('delete ignores user own leadership level (pure permission check)', function (): void {
    $user = User::factory()->create();

    // Create HIGH rank level to delete (rank 1 = CEO)
    $highLevel = LeadershipLevel::factory()->create([
        'tenant_id' => $this->tenant->id,
        'rank' => 1,
        'name' => 'CEO',
    ]);

    // User tries to delete HIGH rank level
    // WITHOUT permission → cannot delete (even though level is empty)
    expect($this->policy->delete($user, $highLevel))->toBeFalse();

    // WITH permission → can delete (regardless of level rank)
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.delete');
    expect($this->policy->delete($user, $highLevel))->toBeTrue();
});

// ========================================================================
// restore() Tests
// ========================================================================

test('users with leadership_level.update permission can restore soft-deleted leadership levels', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.update');

    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);
    $leadershipLevel->delete(); // Soft delete

    expect($this->policy->restore($user, $leadershipLevel))->toBeTrue();
});

test('users without leadership_level.update permission cannot restore soft-deleted leadership levels', function (): void {
    $user = User::factory()->create();
    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);
    $leadershipLevel->delete(); // Soft delete

    expect($this->policy->restore($user, $leadershipLevel))->toBeFalse();
});

test('restore ignores user own leadership level (pure permission check)', function (): void {
    $user = User::factory()->create();

    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);
    $leadershipLevel->delete(); // Soft delete

    // WITHOUT permission → cannot restore
    expect($this->policy->restore($user, $leadershipLevel))->toBeFalse();

    // WITH permission → can restore
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.update');
    expect($this->policy->restore($user, $leadershipLevel))->toBeTrue();
});

// ========================================================================
// forceDelete() Tests
// ========================================================================

test('users with leadership_level.delete permission can force delete leadership levels', function (): void {
    $user = User::factory()->create();
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.delete');

    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($this->policy->forceDelete($user, $leadershipLevel))->toBeTrue();
});

test('users without leadership_level.delete permission cannot force delete leadership levels', function (): void {
    $user = User::factory()->create();
    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($this->policy->forceDelete($user, $leadershipLevel))->toBeFalse();
});

test('forceDelete ignores user own leadership level (pure permission check)', function (): void {
    $user = User::factory()->create();

    $leadershipLevel = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

    // WITHOUT permission → cannot force delete
    expect($this->policy->forceDelete($user, $leadershipLevel))->toBeFalse();

    // WITH permission → can force delete
    givePermissionWithTenant($user, $this->tenant->id, 'leadership_level.delete');
    expect($this->policy->forceDelete($user, $leadershipLevel))->toBeTrue();
});
