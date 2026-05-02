<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Permission;
use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\QualificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function seedQualificationControllerPermissions(): void
{
    foreach (['qualification.read', 'qualification.write'] as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'sanctum']);
    }
}

function resetQualificationControllerRbacState(): void
{
    DB::table('role_has_permissions')->delete();
    DB::table('model_has_roles')->delete();
    DB::table('model_has_permissions')->delete();
    DB::table('permissions')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property string $token
 */
beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
    resetQualificationControllerRbacState();

    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    seedQualificationControllerPermissions();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('qualification controller RBAC bootstrap', function () {
    test('tolerates pre-seeded permissions', function (): void {
        expect(fn (): mixed => seedQualificationControllerPermissions())->not->toThrow(Exception::class);

        expect(Permission::query()
            ->where('guard_name', 'sanctum')
            ->whereIn('name', ['qualification.read', 'qualification.write'])
            ->count())->toBe(2);
    });
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

    test('returns 403 when policy denies viewAny even with qualification.read permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.read');

        $this->partialMock(QualificationPolicy::class, function ($mock): void {
            $mock->shouldReceive('viewAny')->andReturn(false);
        });

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

    test('returns 422 for an invalid category filter', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/qualifications?category=not-a-real-category');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    });

    test('does not filter by null when category is sent as empty string', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.read');

        Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category' => 'first_aid',
        ]);

        Qualification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category' => 'fire_safety',
        ]);

        // Sending ?category= coerces to null via the nullable rule;
        // the filter must be skipped so all qualifications are returned.
        $response = $this->withToken($this->token)
            ->getJson('/v1/qualifications?category=');

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(2);
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
            ])
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'tenant_id',
                    'name',
                    'description',
                    'category',
                    'requires_renewal',
                    'renewal_period_months',
                    'is_mandatory',
                    'is_system_qualification',
                    'sort_order',
                    'created_at',
                    'updated_at',
                ],
            ]);
    });

    test('returns 404 for invalid qualification id format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'qualification.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/qualifications/1');

        $response->assertNotFound()
            ->assertJson(['message' => 'Resource not found.']);
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

        $response->assertStatus(403)
            ->assertJsonStructure(['message']);
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

        $response->assertNoContent();
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

        $response->assertStatus(403)
            ->assertJsonStructure(['message']);
    });
});
