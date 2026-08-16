<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property string $token
 * @property Customer $customer
 * @property OrganizationalUnit $organizationalUnit
 * @property Site $site
 */
beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    // Create test data structure
    $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->organizationalUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
    ]);
});

afterEach(function (): void {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/sites/{site}/cost-centers', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson("/v1/sites/{$this->site->id}/cost-centers");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks cost-centers.read permission', function (): void {
        $this->user->givePermissionTo('sites.read');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/sites/{$this->site->id}/cost-centers");

        $response->assertStatus(403);
    });

    test('returns 403 when user can read cost centers but cannot view parent site', function (): void {
        $this->user->givePermissionTo('cost-centers.read');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/sites/{$this->site->id}/cost-centers");

        $response->assertForbidden();
    });

    test('returns empty list when site has no cost centers', function (): void {
        $this->user->givePermissionTo(['cost-centers.read', 'sites.read']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/sites/{$this->site->id}/cost-centers");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('returns empty list when user has scoped access to the parent site', function (): void {
        $this->user->givePermissionTo('cost-centers.read');

        SiteAssignment::factory()->indefinite()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/sites/{$this->site->id}/cost-centers");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('lists cost centers for a site', function (): void {
        $this->user->givePermissionTo(['cost-centers.read', 'sites.read']);

        CostCenter::factory(3)->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/sites/{$this->site->id}/cost-centers");

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'code',
                        'name',
                        'activity_type',
                        'description',
                        'is_active',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    });

    test('filters cost centers by active status', function (): void {
        $this->user->givePermissionTo(['cost-centers.read', 'sites.read']);

        CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'is_active' => true,
        ]);

        CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'is_active' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/sites/{$this->site->id}/cost-centers?active_only=1");

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

describe('POST /v1/sites/{site}/cost-centers', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson("/v1/sites/{$this->site->id}/cost-centers", [
            'code' => 'KST-001',
            'name' => 'Reception Duty',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks cost-centers.create permission', function (): void {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson("/v1/sites/{$this->site->id}/cost-centers", [
            'code' => 'KST-001',
            'name' => 'Reception Duty',
        ]);

        $response->assertStatus(403);
    });

    test('validates required fields', function (): void {
        $this->user->givePermissionTo(['cost-centers.create', 'sites.update']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson("/v1/sites/{$this->site->id}/cost-centers", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'name']);
    });

    test('validates code format and length', function (): void {
        $this->user->givePermissionTo(['cost-centers.create', 'sites.update']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson("/v1/sites/{$this->site->id}/cost-centers", [
            'code' => str_repeat('A', 51), // Max 50 chars
            'name' => 'Test',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    });

    test('validates name max length', function (): void {
        $this->user->givePermissionTo(['cost-centers.create', 'sites.update']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson("/v1/sites/{$this->site->id}/cost-centers", [
            'code' => 'KST-001',
            'name' => str_repeat('A', 256), // Max 255 chars
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    test('validates code uniqueness per site', function (): void {
        $this->user->givePermissionTo(['cost-centers.create', 'sites.update']);

        CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'code' => 'KST-EXISTING',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson("/v1/sites/{$this->site->id}/cost-centers", [
            'code' => 'KST-EXISTING',
            'name' => 'Duplicate Code',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    });

    test('allows same code on different sites', function (): void {
        $this->user->givePermissionTo(['cost-centers.create', 'sites.update']);

        $otherSite = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
        ]);

        CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $otherSite->id,
            'code' => 'KST-001',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson("/v1/sites/{$this->site->id}/cost-centers", [
            'code' => 'KST-001',
            'name' => 'Same code, different site',
        ]);

        $response->assertStatus(201);
    });

    test('creates cost center with required fields', function (): void {
        $this->user->givePermissionTo(['cost-centers.create', 'sites.update']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson("/v1/sites/{$this->site->id}/cost-centers", [
            'code' => 'KST-001',
            'name' => 'Reception Duty',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'code',
                    'name',
                    'is_active',
                ],
            ])
            ->assertJsonFragment([
                'code' => 'KST-001',
                'name' => 'Reception Duty',
                'is_active' => true,
            ]);

        $this->assertDatabaseHas('cost_centers', [
            'code' => 'KST-001',
            'name' => 'Reception Duty',
            'site_id' => $this->site->id,
            'tenant_id' => $this->tenant->id,
        ]);
    });

    test('creates cost center with all optional fields', function (): void {
        $this->user->givePermissionTo(['cost-centers.create', 'sites.update']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson("/v1/sites/{$this->site->id}/cost-centers", [
            'code' => 'KST-002',
            'name' => 'Night Shift',
            'activity_type' => 'Security Guard',
            'description' => 'Night security patrol',
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'code' => 'KST-002',
                'name' => 'Night Shift',
                'activity_type' => 'Security Guard',
                'description' => 'Night security patrol',
            ]);
    });
});

describe('GET /v1/sites/{site}/cost-centers/{costCenter}', function () {
    test('returns 401 when not authenticated', function (): void {
        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->getJson("/v1/sites/{$this->site->id}/cost-centers/{$costCenter->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks cost-centers.read permission', function (): void {
        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/sites/{$this->site->id}/cost-centers/{$costCenter->id}");

        $response->assertStatus(403);
    });

    test('shows cost center details', function (): void {
        $this->user->givePermissionTo(['cost-centers.read', 'sites.read']);

        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'code' => 'KST-TEST',
            'name' => 'Test Cost Center',
            'activity_type' => 'Security',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/sites/{$this->site->id}/cost-centers/{$costCenter->id}");

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $costCenter->id,
                'code' => 'KST-TEST',
                'name' => 'Test Cost Center',
                'activity_type' => 'Security',
            ]);
    });

    test('returns 404 when cost center belongs to a different site route', function (): void {
        $this->user->givePermissionTo(['cost-centers.read', 'sites.read']);

        $otherSite = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
        ]);

        $foreignCostCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $otherSite->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/sites/{$this->site->id}/cost-centers/{$foreignCostCenter->id}");

        $response->assertStatus(404);
    });

    test('returns 404 for invalid cost center id format', function (): void {
        $this->user->givePermissionTo(['cost-centers.read', 'sites.read']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/v1/sites/{$this->site->id}/cost-centers/1");

        $response->assertNotFound()
            ->assertJson(['message' => 'Resource not found.']);
    });
});

describe('PUT /v1/sites/{site}/cost-centers/{costCenter}', function () {
    test('returns 401 when not authenticated', function (): void {
        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->putJson("/v1/sites/{$this->site->id}/cost-centers/{$costCenter->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks cost-centers.update permission', function (): void {
        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->putJson("/v1/sites/{$this->site->id}/cost-centers/{$costCenter->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(403);
    });

    test('updates cost center name', function (): void {
        $this->user->givePermissionTo(['cost-centers.update', 'sites.update']);

        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'code' => 'KST-001',
            'name' => 'Old Name',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->putJson("/v1/sites/{$this->site->id}/cost-centers/{$costCenter->id}", [
            'code' => 'KST-001',
            'name' => 'Updated Name',
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'name' => 'Updated Name',
            ]);

        $this->assertDatabaseHas('cost_centers', [
            'id' => $costCenter->id,
            'name' => 'Updated Name',
        ]);
    });

    test('returns 404 when updating cost center through a different site route', function (): void {
        $this->user->givePermissionTo(['cost-centers.update', 'sites.update']);

        $otherSite = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
        ]);

        $foreignCostCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $otherSite->id,
            'code' => 'KST-OTHER',
            'name' => 'Other Site Cost Center',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->putJson("/v1/sites/{$this->site->id}/cost-centers/{$foreignCostCenter->id}", [
            'code' => 'KST-OTHER',
            'name' => 'Hijacked Name',
        ]);

        $response->assertStatus(404);

        $foreignCostCenter->refresh();
        expect($foreignCostCenter->name)->toBe('Other Site Cost Center');
    });

    test('validates code uniqueness on update', function (): void {
        $this->user->givePermissionTo(['cost-centers.update', 'sites.update']);

        CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'code' => 'KST-EXISTING',
        ]);

        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'code' => 'KST-001',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->putJson("/v1/sites/{$this->site->id}/cost-centers/{$costCenter->id}", [
            'code' => 'KST-EXISTING',
            'name' => 'Updated',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    });
});

describe('DELETE /v1/sites/{site}/cost-centers/{costCenter}', function () {
    test('returns 401 when not authenticated', function (): void {
        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->deleteJson("/v1/sites/{$this->site->id}/cost-centers/{$costCenter->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks cost-centers.delete permission', function (): void {
        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->deleteJson("/v1/sites/{$this->site->id}/cost-centers/{$costCenter->id}");

        $response->assertStatus(403);
    });

    test('soft deletes cost center', function (): void {
        $this->user->givePermissionTo(['cost-centers.delete', 'sites.update']);

        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->deleteJson("/v1/sites/{$this->site->id}/cost-centers/{$costCenter->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('cost_centers', [
            'id' => $costCenter->id,
        ]);
    });
});
