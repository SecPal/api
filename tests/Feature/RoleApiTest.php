<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    // Create test user with token
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    // Create target user (recipient of role assignments)
    $this->targetUser = User::factory()->create();

    // Create test role
    $this->role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);

    // Create permissions (global, not team-scoped)
    Permission::create(['name' => 'role.assign', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'role.revoke', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'role.read', 'guard_name' => 'sanctum']);
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('POST /v1/users/{id}/roles - Assign Role', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson("/api/v1/users/{$this->targetUser->id}/roles", [
            'role' => 'manager',
            'valid_from' => now()->toIso8601String(),
            'valid_until' => now()->addDays(7)->toIso8601String(),
        ]);

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks role.assign permission', function (): void {
        $response = $this->withToken($this->token)
            ->postJson("/api/v1/users/{$this->targetUser->id}/roles", [
                'role' => 'manager',
                'valid_from' => now()->toIso8601String(),
                'valid_until' => now()->addDays(7)->toIso8601String(),
            ]);

        $response->assertForbidden();
    });

    test('returns 422 when role is missing', function (): void {
        $this->user->givePermissionTo('role.assign');

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/users/{$this->targetUser->id}/roles", [
                'valid_from' => now()->toIso8601String(),
                'valid_until' => now()->addDays(7)->toIso8601String(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    });

    test('returns 422 when valid_until is before valid_from', function (): void {
        $this->user->givePermissionTo('role.assign');

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/users/{$this->targetUser->id}/roles", [
                'role' => 'manager',
                'valid_from' => now()->addDays(7)->toIso8601String(),
                'valid_until' => now()->toIso8601String(), // before valid_from
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);
    });

    test('assigns role with temporal parameters and returns 201', function (): void {
        $this->user->givePermissionTo('role.assign');

        $validFrom = now();
        $validUntil = now()->addDays(7);

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/users/{$this->targetUser->id}/roles", [
                'role' => 'manager',
                'valid_from' => $validFrom->toIso8601String(),
                'valid_until' => $validUntil->toIso8601String(),
                'auto_revoke' => true,
                'reason' => 'Vacation coverage',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'user_id',
                'role',
                'valid_from',
                'valid_until',
                'auto_revoke',
                'reason',
            ])
            ->assertJsonFragment([
                'user_id' => $this->targetUser->id,
                'role' => 'manager',
                'auto_revoke' => true,
                'reason' => 'Vacation coverage',
            ]);

        // Verify role was assigned in database
        expect($this->targetUser->hasRole('manager'))->toBeTrue();
    });
});

describe('GET /v1/users/{id}/roles - List Roles', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson("/api/v1/users/{$this->targetUser->id}/roles");

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks role.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson("/api/v1/users/{$this->targetUser->id}/roles");

        $response->assertForbidden();
    });

    test('returns empty array when user has no roles', function (): void {
        $this->user->givePermissionTo('role.read');

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/users/{$this->targetUser->id}/roles");

        $response->assertOk()
            ->assertJson(['roles' => []]);
    });

    test('returns roles with expiry info when user has roles', function (): void {
        $this->user->givePermissionTo('role.read');

        // Assign role with temporal parameters
        $validFrom = now()->subHours(1);
        $validUntil = now()->addDays(7);

        assignTemporalRole($this->targetUser, $this->role, $this->tenant->id, [
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'auto_revoke' => true,
            'assigned_by' => $this->user->id,
            'reason' => 'Test assignment',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/users/{$this->targetUser->id}/roles");

        $response->assertOk()
            ->assertJsonStructure([
                'roles' => [
                    '*' => [
                        'role',
                        'valid_from',
                        'valid_until',
                        'auto_revoke',
                        'is_active',
                        'is_expired',
                    ],
                ],
            ])
            ->assertJsonFragment([
                'role' => 'manager',
                'auto_revoke' => true,
                'is_active' => true,
                'is_expired' => false,
            ]);
    });
});

describe('DELETE /v1/users/{id}/roles/{role} - Revoke Role', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->deleteJson("/api/v1/users/{$this->targetUser->id}/roles/manager");

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks role.revoke permission', function (): void {
        $response = $this->withToken($this->token)
            ->deleteJson("/api/v1/users/{$this->targetUser->id}/roles/manager");

        $response->assertForbidden();
    });

    test('returns 404 when role not assigned to user', function (): void {
        $this->user->givePermissionTo('role.revoke');

        $response = $this->withToken($this->token)
            ->deleteJson("/api/v1/users/{$this->targetUser->id}/roles/manager");

        $response->assertNotFound();
    });

    test('revokes role and returns 204', function (): void {
        $this->user->givePermissionTo('role.revoke');

        // Assign role first
        assignTemporalRole($this->targetUser, $this->role, $this->tenant->id, [
            'valid_from' => now(),
            'valid_until' => now()->addDays(7),
            'auto_revoke' => true,
            'assigned_by' => $this->user->id,
        ]);

        expect($this->targetUser->hasRole('manager'))->toBeTrue();

        $response = $this->withToken($this->token)
            ->deleteJson("/api/v1/users/{$this->targetUser->id}/roles/manager");

        $response->assertNoContent();

        // Verify role was revoked
        $this->targetUser->refresh();
        expect($this->targetUser->hasRole('manager'))->toBeFalse();
    });
});

describe('PATCH /v1/users/{id}/roles/{role}/extend - Extend Role', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->patchJson("/api/v1/users/{$this->targetUser->id}/roles/manager/extend", [
            'valid_until' => now()->addDays(14)->toIso8601String(),
        ]);

        $response->assertUnauthorized();
    });

    test('returns 403 when user lacks role.assign permission', function (): void {
        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/users/{$this->targetUser->id}/roles/manager/extend", [
                'valid_until' => now()->addDays(14)->toIso8601String(),
            ]);

        $response->assertForbidden();
    });

    test('returns 404 when role not assigned to user', function (): void {
        $this->user->givePermissionTo('role.assign');

        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/users/{$this->targetUser->id}/roles/manager/extend", [
                'valid_until' => now()->addDays(14)->toIso8601String(),
            ]);

        $response->assertNotFound();
    });

    test('returns 422 when new valid_until is before current valid_until', function (): void {
        $this->user->givePermissionTo('role.assign');

        // Assign role with 14 days validity
        assignTemporalRole($this->targetUser, $this->role, $this->tenant->id, [
            'valid_from' => now(),
            'valid_until' => now()->addDays(14),
            'auto_revoke' => true,
            'assigned_by' => $this->user->id,
        ]);

        // Try to "extend" to 7 days (actually shortening)
        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/users/{$this->targetUser->id}/roles/manager/extend", [
                'valid_until' => now()->addDays(7)->toIso8601String(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);
    });

    test('extends role expiration and returns 200', function (): void {
        $this->user->givePermissionTo('role.assign');

        $originalValidUntil = now()->addDays(7);
        $newValidUntil = now()->addDays(14);

        // Assign role
        assignTemporalRole($this->targetUser, $this->role, $this->tenant->id, [
            'valid_from' => now(),
            'valid_until' => $originalValidUntil,
            'auto_revoke' => true,
            'assigned_by' => $this->user->id,
        ]);

        // Extend role
        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/users/{$this->targetUser->id}/roles/manager/extend", [
                'valid_until' => $newValidUntil->toIso8601String(),
                'reason' => 'Extended vacation period',
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user_id',
                'role',
                'valid_from',
                'valid_until',
                'reason',
            ])
            ->assertJsonFragment([
                'user_id' => $this->targetUser->id,
                'role' => 'manager',
                'reason' => 'Extended vacation period',
            ]);

        // Verify expiration was extended in database
        $assignment = \App\Models\TemporalRoleUser::where('model_id', $this->targetUser->id)
            ->where('role_id', $this->role->id)
            ->first();

        expect($assignment->valid_until->toDateString())
            ->toBe($newValidUntil->toDateString());
    });
});

describe('Edge Cases - Temporal Date Validation', function () {
    test('accepts assignment with valid_from in the past and valid_until also past', function (): void {
        $this->user->givePermissionTo('role.assign');

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/users/{$this->targetUser->id}/roles", [
                'role' => 'manager',
                'valid_from' => now()->subDays(10)->toIso8601String(),
                'valid_until' => now()->subDays(5)->toIso8601String(),
            ]);

        // Should accept - validation allows past dates for historical assignments
        $response->assertCreated();
    });

    test('accepts assignment with only valid_from (no end date - unbegrenzt)', function (): void {
        $this->user->givePermissionTo('role.assign');

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/users/{$this->targetUser->id}/roles", [
                'role' => 'manager',
                'valid_from' => now()->toIso8601String(),
            ]);

        // Should accept - allows flexible date combinations
        $response->assertCreated()
            ->assertJsonFragment([
                'role' => 'manager',
            ]);
    });

    test('accepts assignment with only valid_until (no start date)', function (): void {
        $this->user->givePermissionTo('role.assign');

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/users/{$this->targetUser->id}/roles", [
                'role' => 'manager',
                'valid_until' => now()->addDays(7)->toIso8601String(),
            ]);

        // Should accept - allows flexible date combinations
        $response->assertCreated()
            ->assertJsonFragment([
                'role' => 'manager',
            ]);
    });

    test('accepts assignment with neither valid_from nor valid_until (permanent role)', function (): void {
        $this->user->givePermissionTo('role.assign');

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/users/{$this->targetUser->id}/roles", [
                'role' => 'manager',
            ]);

        // Nullable fields allow omitting both for permanent assignments
        $response->assertCreated()
            ->assertJsonFragment([
                'role' => 'manager',
            ]);
    });

    test('rejects extension with past date', function (): void {
        $this->user->givePermissionTo('role.assign');

        // Assign role first
        assignTemporalRole($this->targetUser, $this->role, $this->tenant->id, [
            'valid_from' => now(),
            'valid_until' => now()->addDays(7),
            'assigned_by' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/users/{$this->targetUser->id}/roles/manager/extend", [
                'valid_until' => now()->subDay()->toIso8601String(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);
    });
});

describe('Edge Cases - N+1 Query Prevention', function () {
    test('fetches multiple role assignments efficiently', function (): void {
        $this->user->givePermissionTo('role.read');

        // Create multiple roles in the current tenant context
        $this->registrar->setPermissionsTeamId($this->tenant->id);

        $roles = [
            Role::create(['name' => 'admin']),
            Role::create(['name' => 'editor']),
            Role::create(['name' => 'viewer']),
        ];

        // Assign all roles to target user (including the manager role from beforeEach)
        assignTemporalRole($this->targetUser, $this->role, $this->tenant->id, [
            'valid_from' => now(),
            'valid_until' => now()->addDays(30),
            'assigned_by' => $this->user->id,
        ]);

        foreach ($roles as $role) {
            assignTemporalRole($this->targetUser, $role, $this->tenant->id, [
                'valid_from' => now(),
                'valid_until' => now()->addDays(30),
                'assigned_by' => $this->user->id,
            ]);
        }

        // Enable query log
        DB::enableQueryLog();

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/users/{$this->targetUser->id}/roles");

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Should have minimal queries:
        // 1. Auth user lookup
        // 2. Permission check
        // 3. TemporalRoleUser query
        // 4. Single Role::whereIn() query (NOT one per role)
        // Total should be < 10 queries even with multiple roles
        expect(count($queries))->toBeLessThan(10);

        $response->assertOk()
            ->assertJsonStructure([
                'roles' => [
                    '*' => ['role', 'valid_from', 'valid_until', 'is_active', 'is_expired'],
                ],
            ]);

        // Verify all 4 roles returned (manager + 3 new)
        $data = $response->json();
        expect($data['roles'])->toHaveCount(4);
    });
});
