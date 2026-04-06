<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property User $admin
 */
uses(RefreshDatabase::class)->group('integration', 'rbac');

beforeEach(function (): void {
    // Set up tenant for permission system
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    // Create admin user for tests
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Admin');
});

afterEach(function (): void {
    // Reset tenant context
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('Temporal Role Lifecycle Integration', function (): void {
    test('complete temporal role lifecycle: assign → active → expire → auto-revoke', function (): void {
        $guard = User::factory()->create();
        $managerRole = Role::findByName('Manager');

        // Step 1: Assign temporal role with valid_from and valid_until
        $validFrom = now()->addHours(1);
        $validUntil = now()->addHours(3);

        actingAs($this->admin)
            ->postJson("/v1/users/{$guard->id}/roles", [
                'role' => 'Manager',
                'valid_from' => $validFrom->toIso8601String(),
                'valid_until' => $validUntil->toIso8601String(),
                'auto_revoke' => true,
            ])
            ->assertSuccessful();

        // Step 2: Role is not active yet (before valid_from)
        expect($guard->fresh()->hasRole('Manager'))->toBeFalse();

        // Step 3: Travel to active period
        travelTo($validFrom->addMinutes(30));
        expect($guard->fresh()->hasRole('Manager'))->toBeTrue();

        // Step 4: Check permissions are available during active period
        $managerPermissions = $managerRole->permissions->pluck('name')->toArray();
        expect($guard->fresh()->getAllPermissions()->pluck('name')->toArray())
            ->toContain(...array_slice($managerPermissions, 0, 3)); // Check first 3 permissions

        // Step 5: Travel to after expiry time
        travelTo($validUntil->addMinutes(1));

        // Step 6: Run expire command
        Artisan::call('roles:expire');

        // Step 7: Role should be revoked (auto_revoke = true)
        expect($guard->fresh()->hasRole('Manager'))->toBeFalse();
    });

    test('multiple temporal roles can coexist for same user', function (): void {
        $user = User::factory()->create();

        // Assign Manager role (permanent)
        actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/roles", [
                'role' => 'Manager',
            ])
            ->assertSuccessful();

        // Assign temporary Admin role (24 hours)
        actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/roles", [
                'role' => 'Admin',
                'valid_until' => now()->addHours(24)->toIso8601String(),
                'auto_revoke' => true,
            ])
            ->assertSuccessful();

        // User should have both roles
        $user->refresh();
        expect($user->hasRole('Manager'))->toBeTrue();
        expect($user->hasRole('Admin'))->toBeTrue();

        // After expiry, only Manager remains
        travelTo(now()->addHours(25));
        Artisan::call('roles:expire');

        $user->refresh();
        expect($user->hasRole('Manager'))->toBeTrue();
        expect($user->hasRole('Admin'))->toBeFalse();
    });
});

describe('Permission Inheritance Integration', function (): void {
    test('user receives combined permissions from multiple roles and direct assignments', function (): void {
        $user = User::factory()->create();

        // Assign Guard role
        actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/roles", [
                'role' => 'Guard',
            ])
            ->assertSuccessful();

        // Assign Client role
        actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/roles", [
                'role' => 'Client',
            ])
            ->assertSuccessful();

        // Assign direct permission
        actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/permissions", [
                'permissions' => ['employees.export'],
            ])
            ->assertSuccessful();

        // Get all permissions
        $response = actingAs($this->admin)
            ->getJson("/v1/users/{$user->id}/permissions")
            ->assertOk()
            ->json();

        // Verify inheritance: Role permissions ∪ Direct permissions
        expect($response['data']['all'])
            ->toContain('employees.read') // from Guard or Client role
            ->toContain('employees.export') // direct permission
            ->toContain('shifts.read'); // from Guard or Client role

        // Verify direct permissions are separated (array of objects with 'name' property)
        $directPermissionNames = collect($response['data']['direct'])->pluck('name')->toArray();
        expect($directPermissionNames)->toContain('employees.export');
    });

    test('direct permission overrides are independent of role changes', function (): void {
        $user = User::factory()->create();

        // Assign Manager role
        actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/roles", [
                'role' => 'Manager',
            ])
            ->assertSuccessful();

        // Assign direct permission
        actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/permissions", [
                'permissions' => ['reports.generate'],
            ])
            ->assertSuccessful();

        // User should have Manager permissions + direct permission
        $user->refresh();
        expect($user->hasPermissionTo('employees.read'))->toBeTrue(); // from Manager
        expect($user->hasPermissionTo('reports.generate'))->toBeTrue(); // direct

        // Revoke Manager role (use role name, not ID)
        actingAs($this->admin)
            ->deleteJson("/v1/users/{$user->id}/roles/Manager")
            ->assertSuccessful();

        // Direct permission remains
        $user->refresh();
        expect($user->hasPermissionTo('employees.read'))->toBeFalse(); // Manager gone
        expect($user->hasPermissionTo('reports.generate'))->toBeTrue(); // direct remains
    });
});

describe('Multi-User Role Assignment Scenarios', function (): void {
    test('vacation coverage scenario with role handoff', function (): void {
        $managerA = User::factory()->create();
        $managerB = User::factory()->create();

        // Manager A has permanent role
        actingAs($this->admin)
            ->postJson("/v1/users/{$managerA->id}/roles", [
                'role' => 'Manager',
            ])
            ->assertSuccessful();

        // Manager B gets temporary Manager role during vacation (starting 7 days from now)
        $vacationStart = now()->addDays(7)->startOfDay();
        $vacationEnd = now()->addDays(21)->endOfDay();

        actingAs($this->admin)
            ->postJson("/v1/users/{$managerB->id}/roles", [
                'role' => 'Manager',
                'valid_from' => $vacationStart->toIso8601String(),
                'valid_until' => $vacationEnd->toIso8601String(),
                'auto_revoke' => true,
                'reason' => 'Vacation coverage for Manager A',
            ])
            ->assertSuccessful();

        // Before vacation: Only Manager A has role
        expect($managerA->fresh()->hasRole('Manager'))->toBeTrue();
        expect($managerB->fresh()->hasRole('Manager'))->toBeFalse();

        // During vacation: Both have role
        travelTo($vacationStart->copy()->addDays(3));
        expect($managerA->fresh()->hasRole('Manager'))->toBeTrue();
        expect($managerB->fresh()->hasRole('Manager'))->toBeTrue();

        // After vacation: Only Manager A has role
        travelTo($vacationEnd->copy()->addDay());
        Artisan::call('roles:expire');

        expect($managerA->fresh()->hasRole('Manager'))->toBeTrue();
        expect($managerB->fresh()->hasRole('Manager'))->toBeFalse();
    });
});

describe('Error Handling & Edge Cases', function (): void {
    test('cannot assign role that does not exist', function (): void {
        $user = User::factory()->create();

        actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/roles", [
                'role' => 'NonExistentRole',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    });

    test('cannot assign temporal role with invalid date range', function (): void {
        $user = User::factory()->create();

        // valid_from is after valid_until (invalid)
        $response = actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/roles", [
                'role' => 'Manager',
                'valid_from' => now()->addDays(10)->toIso8601String(),
                'valid_until' => now()->addDays(5)->toIso8601String(),
            ]);

        // Should return 422 Unprocessable Entity
        expect($response->status())->toBe(422);
    });

    test('role assignment is idempotent', function (): void {
        $user = User::factory()->create();

        // Assign role first time
        actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/roles", [
                'role' => 'Manager',
            ])
            ->assertSuccessful();

        // Assign same role again - should be idempotent (return 200 OK)
        actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/roles", [
                'role' => 'Manager',
            ])
            ->assertOk() // 200 OK (not 201 Created)
            ->assertJson([
                'message' => 'Role already assigned to user',
                'role' => 'Manager',
            ]);

        // User should still have exactly 1 role (not duplicate)
        expect($user->fresh()->roles)->toHaveCount(1);
    });

    test('idempotency returns existing role with different temporal parameters', function (): void {
        $user = User::factory()->create();

        // Assign permanent role (without temporal constraints)
        actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/roles", [
                'role' => 'Manager',
            ])
            ->assertCreated();

        // Try to assign same role again with temporal parameters
        // Should return 200 OK with existing assignment unchanged
        $response = actingAs($this->admin)
            ->postJson("/v1/users/{$user->id}/roles", [
                'role' => 'Manager',
                'valid_from' => now()->toIso8601String(),
                'valid_until' => now()->addDays(7)->toIso8601String(),
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Role already assigned to user',
                'role' => 'Manager',
            ]);

        // Idempotency: Returns existing assignment, does NOT modify it
        // Note: valid_from is set to now() by default even for "permanent" roles
        expect($response->json('valid_from'))->not->toBeNull()
            ->and($response->json('valid_until'))->toBeNull(); // No expiration

        // User should still have exactly 1 role (not duplicate)
        expect($user->fresh()->roles)->toHaveCount(1);
    });
});
