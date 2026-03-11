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

/**
 * @property TenantKey $tenant
 * @property User $owner
 * @property User $sharedUser
 * @property mixed $secret
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Seed roles and permissions
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();
    $this->sharedUser = User::factory()->create();

    $this->secret = createTestSecret([
        'tenant_id' => $this->tenant->id,
        'owner_id' => $this->owner->id,
        'title_plain' => 'Shared Secret',
    ]);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('secret share uses UUID primary key', function (): void {
    $share = SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => $this->sharedUser->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
        'granted_at' => now(),
    ]);

    expect($share->id)->toBeString();
    expect(strlen($share->id))->toBe(36); // UUID format
});

test('secret share belongs to secret', function (): void {
    $share = SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => $this->sharedUser->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
        'granted_at' => now(),
    ]);

    expect($share->secret)->toBeInstanceOf(Secret::class);
    expect($share->secret->id)->toBe($this->secret->id);
});

test('secret share belongs to user when user-based', function (): void {
    $share = SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => $this->sharedUser->id,
        'permission' => 'write',
        'granted_by' => $this->owner->id,
        'granted_at' => now(),
    ]);

    expect($share->user)->toBeInstanceOf(User::class);
    expect($share->user->id)->toBe($this->sharedUser->id);
});

test('secret share belongs to role when role-based', function (): void {
    $role = Role::firstWhere('name', 'Manager');

    $share = SecretShare::create([
        'secret_id' => $this->secret->id,
        'role_id' => $role->id,
        'permission' => 'admin',
        'granted_by' => $this->owner->id,
        'granted_at' => now(),
    ]);

    expect($share->role)->toBeInstanceOf(Role::class);
});

test('secret share belongs to granter', function (): void {
    $share = SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => $this->sharedUser->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
        'granted_at' => now(),
    ]);

    expect($share->granter)->toBeInstanceOf(User::class);
    expect($share->granter->id)->toBe($this->owner->id);
});

test('secret has many shares', function (): void {
    $user2 = User::factory()->create();

    SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => $this->sharedUser->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
        'granted_at' => now(),
    ]);

    SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => $user2->id,
        'permission' => 'write',
        'granted_by' => $this->owner->id,
        'granted_at' => now(),
    ]);

    expect($this->secret->shares)->toHaveCount(2);
});

test('active scope filters non-expired shares', function (): void {
    // Active share (no expiration)
    SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => $this->sharedUser->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
        'granted_at' => now(),
        'expires_at' => null,
    ]);

    // Active share (expires in future)
    SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => User::factory()->create()->id,
        'permission' => 'write',
        'granted_by' => $this->owner->id,
        'granted_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);

    // Expired share
    SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => User::factory()->create()->id,
        'permission' => 'admin',
        'granted_by' => $this->owner->id,
        'granted_at' => now()->subDays(8),
        'expires_at' => now()->subDay(),
    ]);

    $activeShares = SecretShare::active()->get();
    expect($activeShares)->toHaveCount(2);
});

test('is_expired accessor returns false for permanent share', function (): void {
    $share = SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => $this->sharedUser->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
        'granted_at' => now(),
        'expires_at' => null,
    ]);

    expect($share->is_expired)->toBeFalse();
});

test('is_expired accessor returns false for future expiration', function (): void {
    $share = SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => $this->sharedUser->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
        'granted_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);

    expect($share->is_expired)->toBeFalse();
});

test('is_expired accessor returns true for past expiration', function (): void {
    $share = SecretShare::create([
        'secret_id' => $this->secret->id,
        'user_id' => $this->sharedUser->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
        'granted_at' => now()->subDays(8),
        'expires_at' => now()->subDay(),
    ]);

    expect($share->is_expired)->toBeTrue();
});
