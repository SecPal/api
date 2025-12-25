<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\LeadershipLevel;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tests for LeadershipLevelFactory.
 *
 * Verifies factory creates valid leadership level instances with correct structure.
 *
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/423 Issue #423: Leadership Levels Database Migrations
 * @see https://github.com/SecPal/api/issues/424 Issue #424: LeadershipLevel Model & Factory (dependency)
 */
test('factory creates leadership level with default state', function (): void {
    $tenant = TenantKey::factory()->create();

    $level = LeadershipLevel::factory()->create(['tenant_id' => $tenant->id]);

    expect($level)
        ->id->not->toBeNull()
        ->tenant_id->toBe($tenant->id)
        ->rank->toBeGreaterThan(0)
        ->rank->toBeLessThanOrEqual(255)
        ->name->not->toBeNull()
        ->is_active->toBeTrue();
});

test('factory can create inactive leadership level', function (): void {
    $tenant = TenantKey::factory()->create();

    $level = LeadershipLevel::factory()
        ->inactive()
        ->create(['tenant_id' => $tenant->id]);

    expect($level->is_active)->toBeFalse();
});

test('factory can create leadership level with specific rank', function (): void {
    $tenant = TenantKey::factory()->create();

    $level = LeadershipLevel::factory()
        ->rank(3)
        ->create(['tenant_id' => $tenant->id]);

    expect($level->rank)->toBe(3);
});

test('factory can create leadership level with specific name', function (): void {
    $tenant = TenantKey::factory()->create();

    $level = LeadershipLevel::factory()
        ->named('CEO')
        ->create(['tenant_id' => $tenant->id]);

    expect($level->name)->toBe('CEO');
});

test('factory can create leadership level with color', function (): void {
    $tenant = TenantKey::factory()->create();

    $level = LeadershipLevel::factory()
        ->colored('#FF5733')
        ->create(['tenant_id' => $tenant->id]);

    expect($level->color)->toBe('#FF5733');
});

test('factory respects unique constraints', function (): void {
    $tenant = TenantKey::factory()->create();

    // Create first level with rank 1
    LeadershipLevel::factory()->create([
        'tenant_id' => $tenant->id,
        'rank' => 1,
    ]);

    // Attempting to create another with same rank should fail
    expect(fn () => LeadershipLevel::factory()->create([
        'tenant_id' => $tenant->id,
        'rank' => 1,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('factory creates levels with different tenants independently', function (): void {
    $tenant1 = TenantKey::factory()->create();
    $tenant2 = TenantKey::factory()->create();

    // Both tenants can have rank 1
    $level1 = LeadershipLevel::factory()->create([
        'tenant_id' => $tenant1->id,
        'rank' => 1,
    ]);

    $level2 = LeadershipLevel::factory()->create([
        'tenant_id' => $tenant2->id,
        'rank' => 1,
    ]);

    expect($level1->rank)->toBe(1);
    expect($level2->rank)->toBe(1);
    expect($level1->tenant_id)->not->toBe($level2->tenant_id);
});
