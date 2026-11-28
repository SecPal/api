<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Tenant;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * @property Tenant $tenant
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    TenantKey::ensureKekExists();

    // Create tenant

    $this->tenant = Tenant::factory()->create();
});

describe('User-Tenant Relationship', function () {
    test('user belongs to tenant via tenant() relationship', function () {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        expect($user->tenant)->toBeInstanceOf(Tenant::class);
        expect($user->tenant->id)->toBe($this->tenant->id);
    });

    test('tenant has many users via users() relationship', function () {
        $user1 = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user2 = User::factory()->create(['tenant_id' => $this->tenant->id]);

        expect($this->tenant->users)->toHaveCount(2);
        expect($this->tenant->users->pluck('id')->toArray())->toContain($user1->id, $user2->id);
    });

    test('user tenant relationship is eager loadable', function () {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $loadedUser = User::with('tenant')->find($user->id);

        expect($loadedUser->relationLoaded('tenant'))->toBeTrue();
        expect($loadedUser->tenant->id)->toBe($this->tenant->id);
    });

    test('tenant users relationship is eager loadable', function () {
        User::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);

        $loadedTenant = Tenant::with('users')->find($this->tenant->id);

        expect($loadedTenant->relationLoaded('users'))->toBeTrue();
        expect($loadedTenant->users)->toHaveCount(3);
    });

    test('restricts tenant deletion and preserves users', function () {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        expect(User::find($user->id))->not->toBeNull();

        expect(fn () => DB::transaction(fn () => $this->tenant->delete()))->toThrow(QueryException::class, '23001');

        expect(User::find($user->id))->not->toBeNull();
    });

    test('multiple tenants can exist with separate users', function () {

        $tenant2 = Tenant::factory()->create();

        $user1 = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

        expect($this->tenant->users)->toHaveCount(1);
        expect($tenant2->users)->toHaveCount(1);
        expect($user1->tenant->id)->toBe($this->tenant->id);
        expect($user2->tenant->id)->toBe($tenant2->id);
    });
});
