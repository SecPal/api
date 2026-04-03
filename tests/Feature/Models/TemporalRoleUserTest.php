<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property User $user
 * @property Role $managerRole
 * @property Role $guardRole
 */
uses(RefreshDatabase::class);

describe('TemporalRoleUser Pivot Model', function () {
    beforeEach(function () {
        // Use process-specific KEK file for parallel test isolation
        TenantKey::setKekPath(getTestKekPath());
        TenantKey::generateKek();
        $keys = TenantKey::generateEnvelopeKeys();
        $this->tenant = TenantKey::create($keys);

        // Get Permission Registrar for tenant context management
        $this->registrar = app(PermissionRegistrar::class);
        $this->registrar->setPermissionsTeamId($this->tenant->id);

        // Create user (no tenant_id column in users table)
        $this->user = User::factory()->create();

        // Create roles within tenant context
        $this->managerRole = Role::create(['name' => 'manager']);
        $this->guardRole = Role::create(['name' => 'guard']);
    });

    afterEach(function () {
        // Reset tenant context after each test
        $this->registrar->setPermissionsTeamId(null);

        // Cleanup test KEK file
        cleanupTestKekFile();
        TenantKey::setKekPath(null);
    });

    describe('Temporal Filtering', function () {
        it('can assign role with temporal validity (valid_from + valid_until)', function () {
            $validFrom = now()->subHour();
            $validUntil = now()->addHour();

            // Assign temporal role using test helper
            assignTemporalRole($this->user, $this->managerRole, $this->tenant->id, [
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'assigned_by' => null,
                'reason' => 'Test temporal assignment',
            ]);

            $roles = $this->user->roles()->get();

            expect($roles)->toHaveCount(1);
            expect($roles->first()->id)->toBe($this->managerRole->id);

            // Compare timestamps at second precision
            expect($roles->first()->pivot->valid_from->toDateTimeString())->toBe($validFrom->toDateTimeString());
            expect($roles->first()->pivot->valid_until->toDateTimeString())->toBe($validUntil->toDateTimeString());
        });

        it('filters out future roles (valid_from > now)', function () {
            // Assign future role
            assignTemporalRole($this->user, $this->managerRole, $this->tenant->id, [
                'valid_from' => now()->addDay(),
                'valid_until' => now()->addDays(2),
            ]);

            // User's roles() relationship should filter out future roles
            $roles = $this->user->roles()->get();

            expect($roles)->toHaveCount(0);
        });

        it('filters out expired roles (valid_until < now)', function () {
            // Assign expired role
            assignTemporalRole($this->user, $this->managerRole, $this->tenant->id, [
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
            ]);

            // User's roles() relationship should filter out expired roles
            $roles = $this->user->roles()->get();

            expect($roles)->toHaveCount(0);
        });

        it('includes currently active roles (valid_from <= now <= valid_until)', function () {
            // Assign active role
            assignTemporalRole($this->user, $this->managerRole, $this->tenant->id, [
                'valid_from' => now()->subHour(),
                'valid_until' => now()->addHour(),
            ]);

            $roles = $this->user->roles()->get();

            expect($roles)->toHaveCount(1);
            expect($roles->first()->id)->toBe($this->managerRole->id);
        });

        it('includes permanent roles (no temporal bounds)', function () {
            // Assign permanent role (no valid_from/valid_until)
            assignTemporalRole($this->user, $this->guardRole, $this->tenant->id);

            $roles = $this->user->roles()->get();

            expect($roles)->toHaveCount(1);
            expect($roles->first()->id)->toBe($this->guardRole->id);
            expect($roles->first()->pivot->valid_from)->toBeNull();
            expect($roles->first()->pivot->valid_until)->toBeNull();
        });

        it('filters roles to the active permission team', function () {
            $otherTenantKeys = TenantKey::generateEnvelopeKeys();
            $otherTenant = TenantKey::create($otherTenantKeys);

            assignTemporalRole($this->user, $this->managerRole, $this->tenant->id);

            $this->registrar->setPermissionsTeamId($otherTenant->id);
            $otherRole = Role::create(['name' => 'external-manager']);
            assignTemporalRole($this->user, $otherRole, $otherTenant->id);

            $this->registrar->setPermissionsTeamId($this->tenant->id);

            $roleIds = $this->user->roles()->pluck('id');

            expect($roleIds)->toHaveCount(1)
                ->and($roleIds)->toContain($this->managerRole->id)
                ->and($roleIds)->not->toContain($otherRole->id);
        });
    });

    describe('Query Scopes', function () {
        it('active() scope returns only active roles', function () {
            // Create 3 roles with different temporal states
            $futureRole = Role::create(['name' => 'future']);
            $activeRole = Role::create(['name' => 'active']);
            $expiredRole = Role::create(['name' => 'expired']);

            // Assign roles with different temporal states
            assignTemporalRole($this->user, $futureRole, $this->tenant->id, [
                'valid_from' => now()->addDay(),
                'valid_until' => now()->addDays(2),
            ]);

            assignTemporalRole($this->user, $activeRole, $this->tenant->id, [
                'valid_from' => now()->subHour(),
                'valid_until' => now()->addHour(),
            ]);

            assignTemporalRole($this->user, $expiredRole, $this->tenant->id, [
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
            ]);

            // Query using active() scope directly on TemporalRoleUser
            $activeAssignments = App\Models\TemporalRoleUser::query()
                ->where('model_id', $this->user->id)
                ->where('model_type', User::class)
                ->where('tenant_id', $this->tenant->id)
                ->active()
                ->get();

            expect($activeAssignments)->toHaveCount(1);
            expect($activeAssignments->first()->role_id)->toBe($activeRole->id);
        });

        it('expired() scope returns only expired roles (with auto_revoke=true)', function () {
            // Create expired roles with different auto_revoke settings
            $expiredRoleAutoRevoke = Role::create(['name' => 'expired-auto']);
            $expiredRoleNoRevoke = Role::create(['name' => 'expired-no-revoke']);
            $activeRole = Role::create(['name' => 'active']);

            // Assign expired role with auto_revoke=true
            assignTemporalRole($this->user, $expiredRoleAutoRevoke, $this->tenant->id, [
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => true,
            ]);

            // Assign expired role with auto_revoke=false
            assignTemporalRole($this->user, $expiredRoleNoRevoke, $this->tenant->id, [
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => false,
            ]);

            // Assign active role
            assignTemporalRole($this->user, $activeRole, $this->tenant->id, [
                'valid_from' => now()->subHour(),
                'valid_until' => now()->addHour(),
            ]);

            // Query using expired() scope
            $expiredAssignments = App\Models\TemporalRoleUser::query()
                ->where('model_id', $this->user->id)
                ->where('model_type', User::class)
                ->where('tenant_id', $this->tenant->id)
                ->expired()
                ->get();

            // Only auto_revoke=true expired roles should be returned
            expect($expiredAssignments)->toHaveCount(1);
            expect($expiredAssignments->first()->role_id)->toBe($expiredRoleAutoRevoke->id);
        });
    });

    describe('Helper Methods', function () {
        it('isActive() correctly identifies active assignment', function () {
            assignTemporalRole($this->user, $this->managerRole, $this->tenant->id, [
                'valid_from' => now()->subHour(),
                'valid_until' => now()->addHour(),
            ]);

            $pivot = App\Models\TemporalRoleUser::query()
                ->where('model_id', $this->user->id)
                ->where('model_type', User::class)
                ->where('role_id', $this->managerRole->id)
                ->where('tenant_id', $this->tenant->id)
                ->first();

            expect($pivot->isActive())->toBeTrue();
        });

        it('isActive() correctly identifies inactive assignment (future)', function () {
            assignTemporalRole($this->user, $this->managerRole, $this->tenant->id, [
                'valid_from' => now()->addDay(),
                'valid_until' => now()->addDays(2),
            ]);

            $pivot = App\Models\TemporalRoleUser::query()
                ->where('model_id', $this->user->id)
                ->where('model_type', User::class)
                ->where('role_id', $this->managerRole->id)
                ->where('tenant_id', $this->tenant->id)
                ->first();

            expect($pivot->isActive())->toBeFalse();
        });

        it('isExpired() correctly identifies expired assignment', function () {
            assignTemporalRole($this->user, $this->managerRole, $this->tenant->id, [
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
            ]);

            $pivot = App\Models\TemporalRoleUser::query()
                ->where('model_id', $this->user->id)
                ->where('model_type', User::class)
                ->where('role_id', $this->managerRole->id)
                ->where('tenant_id', $this->tenant->id)
                ->first();

            expect($pivot->isExpired())->toBeTrue();
        });

        it('isExpired() correctly identifies non-expired assignment', function () {
            assignTemporalRole($this->user, $this->managerRole, $this->tenant->id, [
                'valid_from' => now()->subHour(),
                'valid_until' => now()->addHour(),
            ]);

            $pivot = App\Models\TemporalRoleUser::query()
                ->where('model_id', $this->user->id)
                ->where('model_type', User::class)
                ->where('role_id', $this->managerRole->id)
                ->where('tenant_id', $this->tenant->id)
                ->first();

            expect($pivot->isExpired())->toBeFalse();
        });
    });

    describe('Auto-Revoke', function () {
        it('respects auto_revoke flag (only auto_revoke=true roles appear in expired() scope)', function () {
            // Create two roles with different auto_revoke settings
            $role1 = Role::create(['name' => 'role-auto-revoke']);
            $role2 = Role::create(['name' => 'role-no-revoke']);

            // Assign expired role with auto_revoke=true
            assignTemporalRole($this->user, $role1, $this->tenant->id, [
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => true,
            ]);

            // Assign expired role with auto_revoke=false
            assignTemporalRole($this->user, $role2, $this->tenant->id, [
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => false,
            ]);

            // Use expired() scope to get auto-revoke candidates
            $autoRevokeAssignments = App\Models\TemporalRoleUser::query()
                ->where('model_id', $this->user->id)
                ->where('model_type', User::class)
                ->where('tenant_id', $this->tenant->id)
                ->expired()
                ->get();

            expect($autoRevokeAssignments)->toHaveCount(1);
            expect($autoRevokeAssignments->first()->role_id)->toBe($role1->id);
            expect($autoRevokeAssignments->first()->auto_revoke)->toBeTrue();
        });
    });
});
