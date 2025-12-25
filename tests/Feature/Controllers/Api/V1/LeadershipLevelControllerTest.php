<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\LeadershipLevel;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant->id);

    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->user->createToken('test-device')->plainTextToken;
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * Tests for LeadershipLevelController (Operation 1: Leadership Level CRUD).
 *
 * CRITICAL: Per ADR-009, these endpoints use PURE permission-based authorization.
 * User's OWN leadership level has ZERO influence on these operations.
 *
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/424 Issue #424: Leadership Levels Backend API
 */

// ========================================================================
// GET /v1/leadership-levels (index)
// ========================================================================

describe('GET /v1/leadership-levels', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/leadership-levels');
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks leadership_level.view permission', function (): void {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/v1/leadership-levels');

        $response->assertStatus(403);
    });

    test('returns empty list when tenant has no leadership levels', function (): void {
        $this->user->givePermissionTo('leadership_level.view');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/v1/leadership-levels');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('lists all leadership levels for tenant ordered by rank', function (): void {
        $this->user->givePermissionTo('leadership_level.view');

        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 3,
            'name' => 'Branch Director',
        ]);
        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'name' => 'CEO',
        ]);
        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 2,
            'name' => 'Regional Manager',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/v1/leadership-levels');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.rank', 1) // CEO first (lowest rank number)
            ->assertJsonPath('data.1.rank', 2) // Regional Manager second
            ->assertJsonPath('data.2.rank', 3) // Branch Director third
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'tenant_id',
                        'rank',
                        'name',
                        'description',
                        'color',
                        'is_active',
                        'employees_count',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ],
                ],
            ]);
    });

    test('filters leadership levels by is_active', function (): void {
        $this->user->givePermissionTo('leadership_level.view');

        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'is_active' => true,
        ]);
        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 2,
            'is_active' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/v1/leadership-levels?is_active=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    test('includes soft-deleted levels when include_trashed parameter is true', function (): void {
        $this->user->givePermissionTo('leadership_level.view');

        $level = LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
        ]);
        $level->delete(); // Soft delete

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/v1/leadership-levels?include_trashed=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.deleted_at', fn ($value) => $value !== null);
    });

    test('enforces tenant isolation - does not show other tenant levels', function (): void {
        $this->user->givePermissionTo('leadership_level.view');

        // Create level for this tenant
        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'name' => 'My CEO',
        ]);

        // Create level for different tenant
        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        LeadershipLevel::factory()->create([
            'tenant_id' => $otherTenant->id,
            'rank' => 1,
            'name' => 'Other CEO',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/v1/leadership-levels');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'My CEO');
    });
});

// ========================================================================
// POST /v1/leadership-levels (store)
// ========================================================================

describe('POST /v1/leadership-levels', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson('/v1/leadership-levels', [
            'rank' => 1,
            'name' => 'CEO',
        ]);
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks leadership_level.create permission', function (): void {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson('/v1/leadership-levels', [
            'rank' => 1,
            'name' => 'CEO',
        ]);

        $response->assertStatus(403);
    });

    test('creates leadership level successfully', function (): void {
        $this->user->givePermissionTo('leadership_level.create');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson('/v1/leadership-levels', [
            'rank' => 1,
            'name' => 'Chief Executive Officer',
            'description' => 'CEO of the company',
            'color' => '#FF5733',
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.rank', 1)
            ->assertJsonPath('data.name', 'Chief Executive Officer')
            ->assertJsonPath('data.description', 'CEO of the company')
            ->assertJsonPath('data.color', '#FF5733')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.tenant_id', $this->tenant->id);

        $this->assertDatabaseHas('leadership_levels', [
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'name' => 'Chief Executive Officer',
        ]);
    });

    test('defaults is_active to true when not provided', function (): void {
        $this->user->givePermissionTo('leadership_level.create');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson('/v1/leadership-levels', [
            'rank' => 1,
            'name' => 'CEO',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_active', true);
    });

    test('validates required fields', function (): void {
        $this->user->givePermissionTo('leadership_level.create');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson('/v1/leadership-levels', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rank', 'name']);
    });

    test('validates rank is between 1 and 255', function (): void {
        $this->user->givePermissionTo('leadership_level.create');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson('/v1/leadership-levels', [
            'rank' => 0,
            'name' => 'Invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rank']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson('/v1/leadership-levels', [
            'rank' => 256,
            'name' => 'Invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rank']);
    });

    test('validates rank uniqueness within tenant', function (): void {
        $this->user->givePermissionTo('leadership_level.create');

        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'name' => 'Chief Executive Officer',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson('/v1/leadership-levels', [
            'rank' => 1,
            'name' => 'Duplicate Rank',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rank']);
    });

    test('validates name uniqueness within tenant', function (): void {
        $this->user->givePermissionTo('leadership_level.create');

        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'name' => 'Chief Executive Officer',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson('/v1/leadership-levels', [
            'rank' => 2,
            'name' => 'Chief Executive Officer',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('validates color format', function (): void {
        $this->user->givePermissionTo('leadership_level.create');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson('/v1/leadership-levels', [
            'rank' => 1,
            'name' => 'CEO',
            'color' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['color']);
    });

    test('allows same rank across different tenants', function (): void {
        $this->user->givePermissionTo('leadership_level.create');

        // Create level in first tenant
        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'name' => 'Tenant 1 Chief Executive',
        ]);

        // Create second tenant and level
        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherToken = $otherUser->createToken('test')->plainTextToken;

        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);
        $otherUser->givePermissionTo('leadership_level.create');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$otherToken}",
            'X-Tenant-ID' => (string) $otherTenant->id,
        ])->postJson('/v1/leadership-levels', [
            'rank' => 1,
            'name' => 'Tenant 2 Chief Executive',
        ]);

        $response->assertStatus(201);
    });
});

// ========================================================================
// GET /v1/leadership-levels/{leadershipLevel} (show)
// ========================================================================

describe('GET /v1/leadership-levels/{leadershipLevel}', function () {
    test('returns 401 when not authenticated', function (): void {
        $level = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);
        $response = $this->getJson("/v1/leadership-levels/{$level->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks leadership_level.view permission', function (): void {
        $level = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/leadership-levels/{$level->id}");

        $response->assertStatus(403);
    });

    test('shows leadership level details', function (): void {
        $this->user->givePermissionTo('leadership_level.view');

        $level = LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'name' => 'CEO',
            'description' => 'Chief Executive',
            'color' => '#FF5733',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/leadership-levels/{$level->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $level->id)
            ->assertJsonPath('data.rank', 1)
            ->assertJsonPath('data.name', 'CEO')
            ->assertJsonPath('data.description', 'Chief Executive')
            ->assertJsonPath('data.color', '#FF5733');
    });

    test('returns 404 for non-existent leadership level', function (): void {
        $this->user->givePermissionTo('leadership_level.view');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/v1/leadership-levels/'.fake()->uuid());

        $response->assertStatus(404);
    });
    test('returns 403 when trying to view level from different tenant', function (): void {
        $this->user->givePermissionTo('leadership_level.view');

        // Create leadership level for different tenant
        $otherTenant = TenantKey::factory()->create();
        $otherLevel = LeadershipLevel::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/leadership-levels/{$otherLevel->id}");

        // Should return 403 due to Policy tenant check
        $response->assertStatus(403);
    });
});

// ========================================================================
// PATCH /v1/leadership-levels/{leadershipLevel} (update)
// ========================================================================

describe('PATCH /v1/leadership-levels/{leadershipLevel}', function () {
    test('returns 401 when not authenticated', function (): void {
        $level = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);
        $response = $this->patchJson("/v1/leadership-levels/{$level->id}", ['name' => 'Updated']);
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks leadership_level.update permission', function (): void {
        $level = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->patchJson("/v1/leadership-levels/{$level->id}", ['name' => 'Updated']);

        $response->assertStatus(403);
    });

    test('updates leadership level successfully', function (): void {
        $this->user->givePermissionTo('leadership_level.update');

        $level = LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'name' => 'CEO',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->patchJson("/v1/leadership-levels/{$level->id}", [
            'name' => 'Chief Executive Officer',
            'description' => 'Updated description',
            'color' => '#00FF00',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Chief Executive Officer')
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.color', '#00FF00');

        $this->assertDatabaseHas('leadership_levels', [
            'id' => $level->id,
            'name' => 'Chief Executive Officer',
        ]);
    });

    test('supports partial updates (PATCH semantics)', function (): void {
        $this->user->givePermissionTo('leadership_level.update');

        $level = LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'name' => 'CEO',
            'description' => 'Original',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->patchJson("/v1/leadership-levels/{$level->id}", [
            'name' => 'Updated CEO',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated CEO')
            ->assertJsonPath('data.description', 'Original'); // Unchanged
    });

    test('validates uniqueness when updating rank', function (): void {
        $this->user->givePermissionTo('leadership_level.update');

        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'name' => 'Chief Executive Officer',
        ]);

        $level = LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 2,
            'name' => 'Regional Manager',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->patchJson("/v1/leadership-levels/{$level->id}", [
            'rank' => 1, // Conflict with CEO
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rank']);
    });

    test('returns 403 when trying to update level from different tenant', function (): void {
        $this->user->givePermissionTo('leadership_level.update');

        // Create leadership level for different tenant
        $otherTenant = TenantKey::factory()->create();
        $otherLevel = LeadershipLevel::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->patchJson("/v1/leadership-levels/{$otherLevel->id}", ['name' => 'Hacked']);

        // Should return 403 due to Policy tenant check
        $response->assertStatus(403);
    });
});

// ========================================================================
// DELETE /v1/leadership-levels/{leadershipLevel} (destroy)
// ========================================================================

describe('DELETE /v1/leadership-levels/{leadershipLevel}', function () {
    test('returns 401 when not authenticated', function (): void {
        $level = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);
        $response = $this->deleteJson("/v1/leadership-levels/{$level->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks leadership_level.delete permission', function (): void {
        $level = LeadershipLevel::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->deleteJson("/v1/leadership-levels/{$level->id}");

        $response->assertStatus(403);
    });

    test('soft deletes leadership level successfully', function (): void {
        $this->user->givePermissionTo('leadership_level.delete');

        $level = LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->deleteJson("/v1/leadership-levels/{$level->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('leadership_levels', [
            'id' => $level->id,
        ]);
    });

    test('returns 403 when trying to delete level from different tenant', function (): void {
        $this->user->givePermissionTo('leadership_level.delete');

        // Create leadership level for different tenant
        $otherTenant = TenantKey::factory()->create();
        $otherLevel = LeadershipLevel::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->deleteJson("/v1/leadership-levels/{$otherLevel->id}");

        // Should return 403 due to Policy tenant check
        $response->assertStatus(403);
    });
});

// ========================================================================
// GET /v1/leadership-levels/available (available)
// ========================================================================

describe('GET /v1/leadership-levels/available', function () {
    test('returns only active leadership levels', function (): void {
        $this->user->givePermissionTo('leadership_level.view');

        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 1,
            'is_active' => true,
        ]);
        LeadershipLevel::factory()->create([
            'tenant_id' => $this->tenant->id,
            'rank' => 2,
            'is_active' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/v1/leadership-levels/available');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });
});
