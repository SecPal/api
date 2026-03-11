<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Permission;
use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property string $token
 */
beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    Permission::create(['name' => 'qualification.read', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'qualification.write', 'guard_name' => 'sanctum']);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/qualifications', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/qualifications');
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks qualification.read permission', function (): void {
        $response = $this->withToken($this->token)->getJson('/v1/qualifications');
        $response->assertStatus(403);
    });

    test('returns system and tenant qualifications', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.read');

        Qualification::factory()->create([
            'tenant_id' => null,
            'is_system_qualification' => true,
            'name' => 'System Qualification',
        ]);

        Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_system_qualification' => false,
            'name' => 'Custom Qualification',
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/qualifications');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'category', 'is_system_qualification'],
                ],
            ]);

        expect($response->json('data'))->toHaveCount(2);
    });

    test('filters by is_system_qualification', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.read');

        Qualification::factory()->create([
            'tenant_id' => null,
            'is_system_qualification' => true,
        ]);

        Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_system_qualification' => false,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/qualifications?is_system_qualification=1');

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['is_system_qualification'])->toBe(true);
    });

    test('filters by category', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.read');

        Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category' => 'first_aid',
        ]);

        Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category' => 'fire_safety',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/qualifications?category=first_aid');

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['category'])->toBe('first_aid');
    });

    test('filters by is_mandatory', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.read');

        Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_mandatory' => true,
        ]);

        Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_mandatory' => false,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/qualifications?is_mandatory=1');

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['is_mandatory'])->toBe(true);
    });
});

describe('POST /v1/qualifications', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson('/v1/qualifications', [
            'name' => 'Test Qualification',
            'category' => 'custom',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks qualification.write permission', function (): void {
        $response = $this->withToken($this->token)->postJson('/v1/qualifications', [
            'name' => 'Test Qualification',
            'category' => 'custom',
            'requires_renewal' => false,
            'is_mandatory' => false,
        ]);

        $response->assertStatus(403);
    });

    test('returns 422 when required fields are missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/qualifications', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'category']);
    });

    test('creates custom qualification with valid data', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/qualifications', [
                'name' => 'Custom First Aid',
                'category' => 'first_aid',
                'description' => 'Company-specific first aid training',
                'requires_renewal' => true,
                'renewal_period_months' => 24,
                'is_mandatory' => false,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'category',
                    'is_system_qualification',
                ],
            ]);

        expect($response->json('data.is_system_qualification'))->toBe(false);
        expect($response->json('data.name'))->toBe('Custom First Aid');
    });

    test('forces is_system_qualification to false for custom qualifications', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/qualifications', [
                'name' => 'Attempt System Qualification',
                'category' => 'specialized',
                'is_system_qualification' => true, // Should be ignored
                'requires_renewal' => false,
                'is_mandatory' => false,
            ]);

        $response->assertStatus(201);
        expect($response->json('data.is_system_qualification'))->toBe(false);
    });
});

describe('GET /v1/qualifications/{qualification}', function () {
    test('returns 401 when not authenticated', function (): void {
        $qualification = Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->getJson("/v1/qualifications/{$qualification->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks qualification.read permission', function (): void {
        $qualification = Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/qualifications/{$qualification->id}");

        $response->assertStatus(403);
    });

    test('returns qualification details with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.read');

        $qualification = Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Qualification',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/qualifications/{$qualification->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $qualification->id,
                    'name' => 'Test Qualification',
                ],
            ]);
    });
});

describe('PATCH /v1/qualifications/{qualification}', function () {
    test('returns 401 when not authenticated', function (): void {
        $qualification = Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->patchJson("/v1/qualifications/{$qualification->id}", [
            'description' => 'Updated description',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks qualification.write permission', function (): void {
        $qualification = Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/qualifications/{$qualification->id}", [
                'description' => 'Updated description',
            ]);

        $response->assertStatus(403);
    });

    test('updates custom qualification with valid data', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.write');

        $qualification = Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_system_qualification' => false,
            'description' => 'Original description',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/qualifications/{$qualification->id}", [
                'description' => 'Updated description',
            ]);

        $response->assertStatus(200);
        expect($response->json('data.description'))->toBe('Updated description');
    });

    test('returns 403 when attempting to update system qualification', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.write');

        $qualification = Qualification::factory()->create([
            'tenant_id' => null,
            'is_system_qualification' => true,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/qualifications/{$qualification->id}", [
                'description' => 'Attempt update',
            ]);

        $response->assertStatus(403);
    });
});

describe('DELETE /v1/qualifications/{qualification}', function () {
    test('returns 401 when not authenticated', function (): void {
        $qualification = Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->deleteJson("/v1/qualifications/{$qualification->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks qualification.write permission', function (): void {
        $qualification = Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/qualifications/{$qualification->id}");

        $response->assertStatus(403);
    });

    test('soft deletes custom qualification with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.write');

        $qualification = Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_system_qualification' => false,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/qualifications/{$qualification->id}");

        $response->assertStatus(204);
        expect(Qualification::withTrashed()->find($qualification->id)->deleted_at)->not->toBeNull();
    });

    test('returns 403 when attempting to delete system qualification', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.write');

        $qualification = Qualification::factory()->create([
            'tenant_id' => null,
            'is_system_qualification' => true,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/qualifications/{$qualification->id}");

        $response->assertStatus(403);
    });
});
