<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\RoleAssignmentLog;
use App\Models\TemporalRoleUser;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property User $user
 * @property User $admin
 * @property Role $role
 */
uses(RefreshDatabase::class);

describe('roles:expire Command', function () {
    beforeEach(function () {
        // Use process-specific KEK file for parallel test isolation
        incrementTestKekCounter();
        TenantKey::setKekPath(getTestKekPath());
        TenantKey::generateKek();
        $keys = TenantKey::generateEnvelopeKeys();
        $this->tenant = TenantKey::create($keys);

        // Get Permission Registrar for tenant context management
        $this->registrar = app(PermissionRegistrar::class);
        $this->registrar->setPermissionsTeamId($this->tenant->id);

        // Create test data
        $this->user = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->role = Role::create(['name' => 'manager']);
    });

    afterEach(function () {
        // Reset tenant context after each test
        $this->registrar->setPermissionsTeamId(null);

        // Cleanup test KEK file
        cleanupTestKekFile();
        TenantKey::setKekPath(null);
    });

    it('identifies expired roles with auto_revoke=true', function () {
        // Create expired role assignment with auto_revoke=true
        assignTemporalRole($this->user, $this->role, $this->tenant->id, [
            'valid_from' => now()->subDays(2),
            'valid_until' => now()->subDay(),
            'auto_revoke' => true,
            'assigned_by' => $this->admin->id,
            'reason' => 'Test expiration',
        ]);

        // Verify assignment exists before command
        expect(TemporalRoleUser::count())->toBe(1);

        // Run command
        Artisan::call('roles:expire');

        // Verify assignment was deleted
        expect(TemporalRoleUser::count())->toBe(0);
    });

    it('does not delete expired roles with auto_revoke=false', function () {
        // Create expired role assignment with auto_revoke=false
        assignTemporalRole($this->user, $this->role, $this->tenant->id, [
            'valid_from' => now()->subDays(2),
            'valid_until' => now()->subDay(),
            'auto_revoke' => false,
            'assigned_by' => $this->admin->id,
        ]);

        // Verify assignment exists before command
        expect(TemporalRoleUser::count())->toBe(1);

        // Run command
        Artisan::call('roles:expire');

        // Verify assignment still exists
        expect(TemporalRoleUser::count())->toBe(1);
    });

    it('logs expired roles to audit trail before deletion', function () {
        $validFrom = now()->subDays(2);
        $validUntil = now()->subDay();

        // Create expired role assignment
        assignTemporalRole($this->user, $this->role, $this->tenant->id, [
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'auto_revoke' => true,
            'assigned_by' => $this->admin->id,
            'reason' => 'Vacation coverage',
        ]);

        // No audit logs before command
        expect(RoleAssignmentLog::count())->toBe(0);

        // Run command
        Artisan::call('roles:expire');

        // Verify audit log was created
        expect(RoleAssignmentLog::count())->toBe(1);

        $log = RoleAssignmentLog::first();
        expect($log->user_id)->toBe($this->user->id);
        expect($log->role_id)->toBe($this->role->id);
        expect($log->action)->toBe('expired');
        expect($log->assigned_by)->toBe($this->admin->id);
        expect($log->reason)->toBe('Vacation coverage');
        expect($log->valid_from->toDateTimeString())->toBe($validFrom->toDateTimeString());
        expect($log->valid_until->toDateTimeString())->toBe($validUntil->toDateTimeString());
    });

    it('does not affect active roles', function () {
        // Create active role assignment
        assignTemporalRole($this->user, $this->role, $this->tenant->id, [
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addHour(),
            'auto_revoke' => true,
        ]);

        // Run command
        Artisan::call('roles:expire');

        // Verify assignment still exists
        expect(TemporalRoleUser::count())->toBe(1);
        expect(RoleAssignmentLog::count())->toBe(0);
    });

    it('does not affect future roles', function () {
        // Create future role assignment
        assignTemporalRole($this->user, $this->role, $this->tenant->id, [
            'valid_from' => now()->addDay(),
            'valid_until' => now()->addDays(2),
            'auto_revoke' => true,
        ]);

        // Run command
        Artisan::call('roles:expire');

        // Verify assignment still exists
        expect(TemporalRoleUser::count())->toBe(1);
        expect(RoleAssignmentLog::count())->toBe(0);
    });

    it('handles multiple expired roles in batch', function () {
        // Create 3 expired roles
        for ($i = 0; $i < 3; $i++) {
            $user = User::factory()->create();
            assignTemporalRole($user, $this->role, $this->tenant->id, [
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => true,
            ]);
        }

        expect(TemporalRoleUser::count())->toBe(3);

        // Run command
        Artisan::call('roles:expire');

        // All expired roles should be deleted
        expect(TemporalRoleUser::count())->toBe(0);
        expect(RoleAssignmentLog::count())->toBe(3);
    });

    it('handles mixed scenarios (expired + active + no auto_revoke)', function () {
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        // Expired with auto_revoke=true (should be deleted)
        assignTemporalRole($this->user, $this->role, $this->tenant->id, [
            'valid_from' => now()->subDays(2),
            'valid_until' => now()->subDay(),
            'auto_revoke' => true,
        ]);

        // Expired with auto_revoke=false (should NOT be deleted)
        assignTemporalRole($user2, $this->role, $this->tenant->id, [
            'valid_from' => now()->subDays(2),
            'valid_until' => now()->subDay(),
            'auto_revoke' => false,
        ]);

        // Active role (should NOT be deleted)
        assignTemporalRole($user3, $this->role, $this->tenant->id, [
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addHour(),
            'auto_revoke' => true,
        ]);

        expect(TemporalRoleUser::count())->toBe(3);

        // Run command
        Artisan::call('roles:expire');

        // Only auto_revoke=true expired role should be deleted
        expect(TemporalRoleUser::count())->toBe(2);
        expect(RoleAssignmentLog::count())->toBe(1);

        // Verify correct role was deleted (user1's expired role)
        $remaining = TemporalRoleUser::pluck('model_id')->toArray();
        expect($remaining)->toContain($user2->id);
        expect($remaining)->toContain($user3->id);
        expect($remaining)->not->toContain($this->user->id);
    });

    it('handles timezone correctly (UTC storage)', function () {
        // Assign role that expired 1 hour ago (in UTC)
        assignTemporalRole($this->user, $this->role, $this->tenant->id, [
            'valid_from' => now()->subDays(1),
            'valid_until' => now()->subHour(),
            'auto_revoke' => true,
        ]);

        // Run command
        Artisan::call('roles:expire');

        // Should be deleted
        expect(TemporalRoleUser::count())->toBe(0);
    });

    it('returns success message when roles are expired', function () {
        assignTemporalRole($this->user, $this->role, $this->tenant->id, [
            'valid_from' => now()->subDays(2),
            'valid_until' => now()->subDay(),
            'auto_revoke' => true,
        ]);

        $exitCode = Artisan::call('roles:expire');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain('expired and revoked');
    });

    it('returns info message when no roles to expire', function () {
        // No expired roles
        $exitCode = Artisan::call('roles:expire');

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain('No expired roles found');
    });

    it('processes large number of expired roles without memory issues', function () {
        // Create 250 expired roles (chunk size will be 100)
        User::factory()->count(250)->create()->each(function ($user, $i) {
            assignTemporalRole($user, $this->role, $this->tenant->id, [
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => true,
                'assigned_by' => $this->admin->id,
                'reason' => "Bulk test {$i}",
            ]);
        });

        expect(TemporalRoleUser::count())->toBe(250);

        // Run command - should process in chunks without memory issues
        $exitCode = Artisan::call('roles:expire');

        // All expired roles should be deleted
        expect($exitCode)->toBe(0);
        expect(TemporalRoleUser::count())->toBe(0);
        expect(RoleAssignmentLog::count())->toBe(250);
        expect(Artisan::output())->toContain('250 role(s) expired and revoked');
    });

    it('prevents duplicate audit logs on concurrent execution', function () {
        // Create expired role
        assignTemporalRole($this->user, $this->role, $this->tenant->id, [
            'valid_from' => now()->subDays(2),
            'valid_until' => now()->subDay(),
            'auto_revoke' => true,
            'assigned_by' => $this->admin->id,
        ]);

        // Simulate concurrent execution by running command twice rapidly
        // First execution should delete and log
        $exitCode1 = Artisan::call('roles:expire');
        expect($exitCode1)->toBe(0);

        // Second execution should find nothing (role already deleted)
        $exitCode2 = Artisan::call('roles:expire');
        expect($exitCode2)->toBe(0);

        // Verify only ONE audit log was created (no duplicates)
        expect(RoleAssignmentLog::count())->toBe(1);
        expect(Artisan::output())->toContain('No expired roles found');
    });

    it('handles chunk boundaries correctly with exactly 100 roles', function () {
        // Create exactly 100 expired roles (one full chunk)
        User::factory()->count(100)->create()->each(function ($user) {
            assignTemporalRole($user, $this->role, $this->tenant->id, [
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => true,
            ]);
        });

        expect(TemporalRoleUser::count())->toBe(100);

        // Run command
        Artisan::call('roles:expire');

        // All should be deleted
        expect(TemporalRoleUser::count())->toBe(0);
        expect(RoleAssignmentLog::count())->toBe(100);
    });

    it('handles chunk boundaries correctly with 101 roles (two chunks)', function () {
        // Create 101 expired roles (forces two chunks: 100 + 1)
        User::factory()->count(101)->create()->each(function ($user) {
            assignTemporalRole($user, $this->role, $this->tenant->id, [
                'valid_from' => now()->subDays(2),
                'valid_until' => now()->subDay(),
                'auto_revoke' => true,
            ]);
        });

        expect(TemporalRoleUser::count())->toBe(101);

        // Run command
        Artisan::call('roles:expire');

        // All should be deleted
        expect(TemporalRoleUser::count())->toBe(0);
        expect(RoleAssignmentLog::count())->toBe(101);
    });
});
