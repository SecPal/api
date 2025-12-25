<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\LeadershipLevel;
use App\Models\TenantKey;
use Database\Seeders\LeadershipLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tests for LeadershipLevelSeeder.
 *
 * Verifies that default leadership levels are correctly seeded for all tenants.
 *
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/423 Issue #423: Leadership Levels Database Migrations
 * @see https://github.com/SecPal/api/issues/424 Issue #424: LeadershipLevel Model & Factory (dependency)
 */
test('seeder creates default leadership levels for tenant', function (): void {
    // This test requires the LeadershipLevel model from Issue #424
    // Skip until #424 is implemented
    $this->markTestSkipped('Requires LeadershipLevel model from Issue #424');

    $tenant = TenantKey::factory()->create();

    $this->artisan('db:seed', ['--class' => LeadershipLevelSeeder::class])->assertSuccessful();

    // Verify 6 default levels were created
    expect(LeadershipLevel::where('tenant_id', $tenant->id)->count())->toBe(6);

    // Verify rank 1 is C-Level
    $cLevel = LeadershipLevel::where('tenant_id', $tenant->id)
        ->where('rank', 1)
        ->first();

    expect($cLevel)
        ->not->toBeNull()
        ->name->toBe('C-Level')
        ->is_active->toBeTrue();
});

test('seeder is idempotent and skips tenants with existing levels', function (): void {
    $this->markTestSkipped('Requires LeadershipLevel model from Issue #424');

    $tenant = TenantKey::factory()->create();

    // Run seeder first time
    $this->artisan('db:seed', ['--class' => LeadershipLevelSeeder::class])->assertSuccessful();
    $firstCount = LeadershipLevel::where('tenant_id', $tenant->id)->count();

    // Run seeder second time
    $this->artisan('db:seed', ['--class' => LeadershipLevelSeeder::class])->assertSuccessful();
    $secondCount = LeadershipLevel::where('tenant_id', $tenant->id)->count();

    expect($secondCount)->toBe($firstCount);
});

test('seeder creates levels for multiple tenants', function (): void {
    $this->markTestSkipped('Requires LeadershipLevel model from Issue #424');

    $tenant1 = TenantKey::factory()->create();
    $tenant2 = TenantKey::factory()->create();

    $this->artisan('db:seed', ['--class' => LeadershipLevelSeeder::class])->assertSuccessful();

    expect(LeadershipLevel::where('tenant_id', $tenant1->id)->count())->toBe(6);
    expect(LeadershipLevel::where('tenant_id', $tenant2->id)->count())->toBe(6);
});

test('seeder creates unique ranks per tenant', function (): void {
    $this->markTestSkipped('Requires LeadershipLevel model from Issue #424');

    $tenant = TenantKey::factory()->create();

    $this->artisan('db:seed', ['--class' => LeadershipLevelSeeder::class])->assertSuccessful();

    $ranks = LeadershipLevel::where('tenant_id', $tenant->id)
        ->pluck('rank')
        ->toArray();

    // All ranks should be unique
    expect($ranks)->toBe(array_unique($ranks));
});

test('seeder respects tenant isolation', function (): void {
    $this->markTestSkipped('Requires LeadershipLevel model from Issue #424');

    $tenant1 = TenantKey::factory()->create();
    $tenant2 = TenantKey::factory()->create();

    $this->artisan('db:seed', ['--class' => LeadershipLevelSeeder::class])->assertSuccessful();

    // Tenant 1 should not see tenant 2's levels
    $tenant1Levels = LeadershipLevel::where('tenant_id', $tenant1->id)->get();
    $tenant2Levels = LeadershipLevel::where('tenant_id', $tenant2->id)->get();

    expect($tenant1Levels)->each(fn ($level) => $level->tenant_id->toBe($tenant1->id));
    expect($tenant2Levels)->each(fn ($level) => $level->tenant_id->toBe($tenant2->id));
});
