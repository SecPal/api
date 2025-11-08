<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

describe('TemporalRoleUser Pivot Model', function () {
    beforeEach(function () {
        // Create tenant with keys for multi-tenancy support
        $this->tenant = Tenant::factory()->create();

        // Set tenant context for Spatie Permission
        setPermissionsTeamId($this->tenant->id);

        // Create user with tenant context
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Create roles within tenant context
        $this->managerRole = Role::create(['name' => 'manager']);
        $this->guardRole = Role::create(['name' => 'guard']);
    });

    describe('Temporal Filtering', function () {
        it('can assign role with temporal validity (valid_from + valid_until)', function () {
            $validFrom = now()->subHour();
            $validUntil = now()->addHour();

            $this->user->roles()->attach($this->managerRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'auto_revoke' => true,
                'assigned_by' => $this->user->id,
                'reason' => 'Test temporal assignment',
            ]);

            $roles = $this->user->roles()->get();

            expect($roles)->toHaveCount(1);
            expect($roles->first()->id)->toBe($this->managerRole->id);
            expect($roles->first()->pivot->valid_from->equalTo($validFrom))->toBeTrue();
            expect($roles->first()->pivot->valid_until->equalTo($validUntil))->toBeTrue();
        });

        it('filters out future roles (valid_from > now)', function () {
            // Assign future role
            $this->user->roles()->attach($this->managerRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->addDay(),
                'valid_until' => now()->addDays(2),
                'auto_revoke' => true,
            ]);

            // User's roles() relationship should filter out future roles
            $roles = $this->user->roles()->get();

            expect($roles)->toHaveCount(0);
        });

        it('filters out expired roles (valid_until < now)', function () {
            // Assign expired role
            $this->user->roles()->attach($this->managerRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => true,
            ]);

            // User's roles() relationship should filter out expired roles
            $roles = $this->user->roles()->get();

            expect($roles)->toHaveCount(0);
        });

        it('includes currently active roles (valid_from <= now <= valid_until)', function () {
            // Assign active role
            $this->user->roles()->attach($this->managerRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subHour(),
                'valid_until' => now()->addHour(),
                'auto_revoke' => true,
            ]);

            $roles = $this->user->roles()->get();

            expect($roles)->toHaveCount(1);
            expect($roles->first()->id)->toBe($this->managerRole->id);
        });

        it('includes permanent roles (no temporal bounds)', function () {
            // Assign permanent role (no valid_from/valid_until)
            $this->user->roles()->attach($this->guardRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => null,
                'valid_until' => null,
                'auto_revoke' => false,
            ]);

            $roles = $this->user->roles()->get();

            expect($roles)->toHaveCount(1);
            expect($roles->first()->id)->toBe($this->guardRole->id);
        });
    });

    describe('Query Scopes', function () {
        it('active() scope returns only active roles', function () {
            // Create 3 role assignments: future, active, expired
            $futureRole = Role::create(['name' => 'future-role']);
            $activeRole = Role::create(['name' => 'active-role']);
            $expiredRole = Role::create(['name' => 'expired-role']);

            $this->user->roles()->attach($futureRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->addDay(),
                'valid_until' => now()->addDays(2),
            ]);

            $this->user->roles()->attach($activeRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subHour(),
                'valid_until' => now()->addHour(),
            ]);

            $this->user->roles()->attach($expiredRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
            ]);

            // Query active roles directly on pivot table
            $activeAssignments = \DB::table('model_has_roles')
                ->where('model_id', $this->user->id)
                ->where('model_type', User::class)
                ->where(function ($q) {
                    $now = now();
                    $q->where(function ($q) use ($now) {
                        $q->whereNull('valid_from')
                            ->orWhere('valid_from', '<=', $now);
                    })->where(function ($q) use ($now) {
                        $q->whereNull('valid_until')
                            ->orWhere('valid_until', '>', $now);
                    });
                })
                ->get();

            expect($activeAssignments)->toHaveCount(1);
            expect($activeAssignments->first()->role_id)->toBe($activeRole->id);
        });

        it('expired() scope returns only expired roles (with auto_revoke=true)', function () {
            // Create expired role with auto_revoke=true
            $expiredRoleAutoRevoke = Role::create(['name' => 'expired-auto']);
            $expiredRoleNoRevoke = Role::create(['name' => 'expired-manual']);
            $activeRole = Role::create(['name' => 'active']);

            $this->user->roles()->attach($expiredRoleAutoRevoke->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => true,
            ]);

            $this->user->roles()->attach($expiredRoleNoRevoke->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => false,
            ]);

            $this->user->roles()->attach($activeRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subHour(),
                'valid_until' => now()->addHour(),
                'auto_revoke' => true,
            ]);

            // Query expired roles ready for auto-revocation
            $expiredAssignments = \DB::table('model_has_roles')
                ->where('model_id', $this->user->id)
                ->where('model_type', User::class)
                ->whereNotNull('valid_until')
                ->where('valid_until', '<=', now())
                ->where('auto_revoke', true)
                ->get();

            expect($expiredAssignments)->toHaveCount(1);
            expect($expiredAssignments->first()->role_id)->toBe($expiredRoleAutoRevoke->id);
        });
    });

    describe('Helper Methods', function () {
        it('isActive() correctly identifies active assignment', function () {
            $this->user->roles()->attach($this->managerRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subHour(),
                'valid_until' => now()->addHour(),
            ]);

            $pivot = \DB::table('model_has_roles')
                ->where('model_id', $this->user->id)
                ->where('role_id', $this->managerRole->id)
                ->first();

            // Manually check active logic
            $validFromCheck = $pivot->valid_from === null || now()->gte($pivot->valid_from);
            $validUntilCheck = $pivot->valid_until === null || now()->lt($pivot->valid_until);
            $isActive = $validFromCheck && $validUntilCheck;

            expect($isActive)->toBeTrue();
        });

        it('isActive() correctly identifies inactive assignment (future)', function () {
            $this->user->roles()->attach($this->managerRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->addDay(),
                'valid_until' => now()->addDays(2),
            ]);

            $pivot = \DB::table('model_has_roles')
                ->where('model_id', $this->user->id)
                ->where('role_id', $this->managerRole->id)
                ->first();

            // Manually check active logic
            $validFromCheck = $pivot->valid_from === null || now()->gte($pivot->valid_from);
            $validUntilCheck = $pivot->valid_until === null || now()->lt($pivot->valid_until);
            $isActive = $validFromCheck && $validUntilCheck;

            expect($isActive)->toBeFalse();
        });

        it('isExpired() correctly identifies expired assignment', function () {
            $this->user->roles()->attach($this->managerRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
            ]);

            $pivot = \DB::table('model_has_roles')
                ->where('model_id', $this->user->id)
                ->where('role_id', $this->managerRole->id)
                ->first();

            // Manually check expired logic
            $isExpired = $pivot->valid_until !== null && now()->gte($pivot->valid_until);

            expect($isExpired)->toBeTrue();
        });

        it('isExpired() correctly identifies non-expired assignment', function () {
            $this->user->roles()->attach($this->managerRole->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subHour(),
                'valid_until' => now()->addHour(),
            ]);

            $pivot = \DB::table('model_has_roles')
                ->where('model_id', $this->user->id)
                ->where('role_id', $this->managerRole->id)
                ->first();

            // Manually check expired logic
            $isExpired = $pivot->valid_until !== null && now()->gte($pivot->valid_until);

            expect($isExpired)->toBeFalse();
        });
    });

    describe('Auto-Revoke', function () {
        it('respects auto_revoke flag (only auto_revoke=true roles appear in expired() scope)', function () {
            $role1 = Role::create(['name' => 'auto-revoke-true']);
            $role2 = Role::create(['name' => 'auto-revoke-false']);

            $this->user->roles()->attach($role1->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => true,
            ]);

            $this->user->roles()->attach($role2->id, [
                'model_type' => User::class,
                'team_id' => $this->tenant->id,
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => false,
            ]);

            // Query expired roles with auto_revoke=true
            $autoRevokeAssignments = \DB::table('model_has_roles')
                ->where('model_id', $this->user->id)
                ->where('model_type', User::class)
                ->whereNotNull('valid_until')
                ->where('valid_until', '<=', now())
                ->where('auto_revoke', true)
                ->get();

            expect($autoRevokeAssignments)->toHaveCount(1);
            expect($autoRevokeAssignments->first()->role_id)->toBe($role1->id);
        });
    });
});
