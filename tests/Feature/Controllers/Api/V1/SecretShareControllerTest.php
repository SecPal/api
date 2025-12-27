<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Secret;
use App\Models\SecretShare;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * @property \App\Models\TenantKey $tenant
 * @property \App\Models\User $owner
 * @property \App\Models\Secret $secret
 * @property \App\Models\User $otherUser
 */
beforeEach(function () {
    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create tenant
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Seed roles and permissions
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    // Create owner user
    $this->owner = User::factory()->create();
    actingAs($this->owner, 'sanctum');

    // Create a secret owned by owner
    $this->secret = createTestSecret([
        'tenant_id' => $this->tenant->id,
        'owner_id' => $this->owner->id,
        'title_plain' => 'Shared Secret',
    ]);

    // Create another user for sharing
    $this->otherUser = User::factory()->create();
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('SecretShareController - Grant Access', function () {
    test('owner can grant read access to user', function () {
        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'user_id' => $this->otherUser->id,
            'permission' => 'read',
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'secret_id',
                    'user_id',
                    'role_id',
                    'permission',
                    'granted_by',
                    'granted_at',
                    'expires_at',
                ],
            ])
            ->assertJsonPath('data.user_id', $this->otherUser->id)
            ->assertJsonPath('data.permission', 'read')
            ->assertJsonPath('data.granted_by', $this->owner->id);

        // Verify database
        expect(SecretShare::count())->toBe(1);
        $share = SecretShare::first();
        expect($share->secret_id)->toBe($this->secret->id)
            ->and($share->user_id)->toBe($this->otherUser->id)
            ->and($share->permission)->toBe('read');
    });

    test('owner can grant write access to role', function () {
        // Arrange
        $role = Role::findByName('Manager', 'sanctum');

        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'role_id' => $role->id,
            'permission' => 'write',
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.role_id', $role->id)
            ->assertJsonPath('data.permission', 'write')
            ->assertJsonPath('data.user_id', null);

        // Verify database
        $share = SecretShare::first();
        expect($share->role_id)->toBe($role->id)
            ->and($share->user_id)->toBeNull();
    });

    test('owner can grant admin access', function () {
        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'user_id' => $this->otherUser->id,
            'permission' => 'admin',
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.permission', 'admin');
    });

    test('owner can grant with expiration date', function () {
        // Arrange
        $expiresAt = now()->addDays(7)->toIso8601String();

        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'user_id' => $this->otherUser->id,
            'permission' => 'read',
            'expires_at' => $expiresAt,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.expires_at', $expiresAt);
    });

    test('cannot grant with both user_id and role_id (XOR constraint)', function () {
        // Arrange
        $role = Role::findByName('Manager', 'sanctum');

        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'user_id' => $this->otherUser->id,
            'role_id' => $role->id,
            'permission' => 'read',
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id', 'role_id']);
    });

    test('cannot grant without user_id or role_id', function () {
        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'permission' => 'read',
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    });

    test('permission is required', function () {
        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'user_id' => $this->otherUser->id,
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['permission']);
    });

    test('permission must be valid enum (read, write, admin)', function () {
        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'user_id' => $this->otherUser->id,
            'permission' => 'invalid',
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['permission']);
    });

    test('expires_at must be future date', function () {
        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'user_id' => $this->otherUser->id,
            'permission' => 'read',
            'expires_at' => now()->subDay()->toIso8601String(),
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['expires_at']);
    });

    test('non-owner cannot grant access', function () {
        // Arrange: Act as different user
        actingAs($this->otherUser, 'sanctum');

        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'user_id' => User::factory()->create()->id,
            'permission' => 'read',
        ]);

        // Assert
        $response->assertForbidden();
    });

    test('cannot grant to non-existent user', function () {
        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'user_id' => '99999999-9999-9999-9999-999999999999',
            'permission' => 'read',
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    });

    test('cannot grant to non-existent role', function () {
        // Act
        $response = postJson("/v1/secrets/{$this->secret->id}/shares", [
            'role_id' => 99999,
            'permission' => 'read',
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id']);
    });
});

describe('SecretShareController - List Shares', function () {
    test('owner can list all shares for secret', function () {
        // Arrange: Create multiple shares
        $role = Role::findByName('Manager', 'sanctum');
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->otherUser->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'role_id' => $role->id,
            'permission' => 'write',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        // Act
        $response = getJson("/v1/secrets/{$this->secret->id}/shares");

        // Assert
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'secret_id',
                        'user_id',
                        'role_id',
                        'permission',
                        'granted_by',
                        'granted_at',
                        'expires_at',
                    ],
                ],
            ]);
    });

    test('non-owner cannot list shares', function () {
        // Arrange: Act as different user
        actingAs($this->otherUser, 'sanctum');

        // Act
        $response = getJson("/v1/secrets/{$this->secret->id}/shares");

        // Assert
        $response->assertForbidden();
    });

    test('list shows only non-expired shares', function () {
        // Arrange: Create expired and active shares
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->otherUser->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
            'expires_at' => now()->subDay(), // Expired
        ]);
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => User::factory()->create()->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
            'expires_at' => now()->addDay(), // Active
        ]);

        // Act
        $response = getJson("/v1/secrets/{$this->secret->id}/shares");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data'); // Only active share
    });
});

describe('SecretShareController - Revoke Access', function () {
    test('owner can revoke share', function () {
        // Arrange: Create share
        $share = SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->otherUser->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        // Act
        $response = deleteJson("/v1/secrets/{$this->secret->id}/shares/{$share->id}");

        // Assert
        $response->assertNoContent();
        expect(SecretShare::count())->toBe(0);
    });

    test('non-owner cannot revoke share', function () {
        // Arrange: Create share, then act as different user
        $share = SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->otherUser->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);
        actingAs($this->otherUser, 'sanctum');

        // Act
        $response = deleteJson("/v1/secrets/{$this->secret->id}/shares/{$share->id}");

        // Assert
        $response->assertForbidden();
        expect(SecretShare::count())->toBe(1); // Share still exists
    });

    test('revoking non-existent share returns 404', function () {
        // Act
        $response = deleteJson("/v1/secrets/{$this->secret->id}/shares/99999999-9999-9999-9999-999999999999");

        // Assert
        $response->assertNotFound();
    });
});
