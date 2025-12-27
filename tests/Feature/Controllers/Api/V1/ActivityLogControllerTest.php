<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Activity;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * Feature tests for ActivityLogController.
 *
 * Tests scoped access control with:
 * - Tenant isolation
 * - Permission checks (activity_log.read)
 * - Organizational scope filtering
 * - Leadership level filtering
 * - Verification endpoints
 *
 * @see \App\Http\Controllers\Api\V1\ActivityLogController
 * @see \App\Policies\ActivityPolicy
 * @see SecPal/api#394 PR-11: ActivityLogController with scoped filtering
 * @see SecPal/api#385 Epic: Activity Logging & Audit Trail Strategy
 *
 * @property TenantKey $tenant
 * @property User $user
 * @property mixed $token
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->user->createToken('test-device')->plainTextToken;
});

afterEach(function (): void {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/activity-logs', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/activity-logs');
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks activity_log.read permission', function (): void {
        $response = $this->withToken($this->token)->getJson('/v1/activity-logs');
        $response->assertStatus(403);
    });

    test('returns empty list when user has permission but no accessible logs', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        $response = $this->withToken($this->token)->getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toBeArray();
        expect($response->json('data'))->toHaveCount(0);
    });

    test('returns global activities (no org unit) to any user with permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        // Create global activity (no organizational_unit_id)
        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
            'log_name' => 'authentication',
            'description' => 'User logged in',
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['log_name'])->toBe('authentication');
    });

    test('returns activities from accessible organizational units', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        // Create organizational unit
        $orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Department A',
        ]);

        // Give user scope to this org unit
        UserInternalOrganizationalScope::factory()->create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'min_viewable_rank' => null,
            'max_viewable_rank' => null,
        ]);

        // Create activity in this org unit
        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $orgUnit->id,
            'log_name' => 'employee_changes',
            'description' => 'Employee updated',
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['organizational_unit_id'])->toBe($orgUnit->id);
    });

    test('excludes activities from inaccessible organizational units', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        $accessibleUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $inaccessibleUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Give user scope only to accessibleUnit
        UserInternalOrganizationalScope::factory()->create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $accessibleUnit->id,
            'access_level' => 'read',
            'min_viewable_rank' => null,
            'max_viewable_rank' => null,
        ]);

        // Create activities in both units
        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $accessibleUnit->id,
            'description' => 'Accessible activity',
        ]);

        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $inaccessibleUnit->id,
            'description' => 'Inaccessible activity',
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toBe('Accessible activity');
    });

    test('filters by leadership level - only shows subordinates activities', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        $orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // User has scope to view management levels 2-5 (subordinates)
        UserInternalOrganizationalScope::factory()->create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'min_viewable_rank' => 2,
            'max_viewable_rank' => 5,
        ]);

        // Create employee users at different management levels
        $seniorManager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $seniorManager->id,
            'organizational_unit_id' => $orgUnit->id,
            'management_level' => 1, // Not visible (above viewable range)
        ]);

        $subordinate = User::factory()->create(['tenant_id' => $this->tenant->id]);
        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $subordinate->id,
            'organizational_unit_id' => $orgUnit->id,
            'management_level' => 3, // Visible (within range 2-5)
        ]);

        // Create activities caused by these users
        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $orgUnit->id,
            'causer_type' => User::class,
            'causer_id' => $seniorManager->id,
            'description' => 'Senior manager activity',
        ]);

        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $orgUnit->id,
            'causer_type' => User::class,
            'causer_id' => $subordinate->id,
            'description' => 'Subordinate activity',
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toBe('Subordinate activity');
    });

    test('shows system-generated activities regardless of leadership level', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        $orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserInternalOrganizationalScope::factory()->create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'min_viewable_rank' => 2,
            'max_viewable_rank' => 5,
        ]);

        // System-generated activity (no causer)
        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $orgUnit->id,
            'causer_type' => null,
            'causer_id' => null,
            'description' => 'System activity',
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toBe('System activity');
    });

    test('filters by date range', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        // Create activities at different dates
        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
            'description' => 'Old activity',
            'created_at' => now()->subDays(10),
        ]);

        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
            'description' => 'Recent activity',
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/activity-logs?from_date='.now()->subDays(3)->toDateString());

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toBe('Recent activity');
    });

    test('filters by log_name', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
            'log_name' => 'authentication',
            'description' => 'User logged in',
        ]);

        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
            'log_name' => 'hr_access',
            'description' => 'HR data accessed',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/activity-logs?log_name=hr_access');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['log_name'])->toBe('hr_access');
    });

    test('searches in description', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
            'description' => 'Employee contract updated',
        ]);

        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
            'description' => 'User logged out',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/activity-logs?search=contract');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toContain('contract');
    });

    test('respects pagination parameters', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        // Create 15 activities
        Activity::factory()->count(15)->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/activity-logs?per_page=5');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(5);
        expect($response->json('meta.per_page'))->toBe(5);
        expect($response->json('meta.total'))->toBe(15);
    });

    test('enforces tenant isolation', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        // Create activity in user's tenant
        Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
            'description' => 'Own tenant activity',
        ]);

        // Create second tenant and activity
        $otherTenant = TenantKey::factory()->create();
        Activity::factory()->create([
            'tenant_id' => $otherTenant->id,
            'organizational_unit_id' => null,
            'description' => 'Other tenant activity',
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toBe('Own tenant activity');
    });
});

describe('GET /v1/activity-logs/{activity}', function () {
    test('returns 401 when not authenticated', function (): void {
        $activity = Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = $this->getJson("/v1/activity-logs/{$activity->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks activity_log.read permission', function (): void {
        $activity = Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/activity-logs/{$activity->id}");

        $response->assertStatus(403);
    });

    test('returns 403 when user tries to access activity from different tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        $otherTenant = TenantKey::factory()->create();
        $activity = Activity::factory()->create([
            'tenant_id' => $otherTenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/activity-logs/{$activity->id}");

        $response->assertStatus(403);
    });

    test('returns global activity when user has permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        $activity = Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
            'log_name' => 'authentication',
            'description' => 'User logged in',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/activity-logs/{$activity->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'log_name',
                    'description',
                    'event_hash',
                    'previous_hash',
                    'created_at',
                ],
            ]);

        expect($response->json('data.id'))->toBe($activity->id);
        expect($response->json('data.log_name'))->toBe('authentication');
    });

    test('returns activity from accessible organizational unit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        $orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserInternalOrganizationalScope::factory()->create([
            'user_id' => $this->user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'min_viewable_rank' => null,
            'max_viewable_rank' => null,
        ]);

        $activity = Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $orgUnit->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/activity-logs/{$activity->id}");

        $response->assertOk();
        expect($response->json('data.id'))->toBe($activity->id);
    });

    test('returns 403 for activity in inaccessible organizational unit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        $inaccessibleUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $activity = Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $inaccessibleUnit->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/activity-logs/{$activity->id}");

        $response->assertStatus(403);
    });

    test('includes verification data when requested', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        $activity = Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
        ]);

        // Hash chain must be built (happens via job in real scenario)
        $activity->refresh();

        $response = $this->withToken($this->token)
            ->getJson("/v1/activity-logs/{$activity->id}?include_verification=1");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'verification' => [
                        'chain_valid',
                        'merkle_valid',
                        'ots_valid',
                    ],
                ],
            ]);
    });
});

describe('GET /v1/activity-logs/{activity}/verify', function () {
    test('returns 401 when not authenticated', function (): void {
        $activity = Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = $this->getJson("/v1/activity-logs/{$activity->id}/verify");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks activity_log.read permission', function (): void {
        $activity = Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/activity-logs/{$activity->id}/verify");

        $response->assertStatus(403);
    });

    test('returns verification results for accessible activity', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        $activity = Activity::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => null,
            'log_name' => 'authentication',
        ]);

        // Hash chain must be built
        $activity->refresh();

        $response = $this->withToken($this->token)
            ->getJson("/v1/activity-logs/{$activity->id}/verify");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'activity_id',
                    'verification' => [
                        'chain_valid',
                        'merkle_valid',
                        'ots_valid',
                    ],
                    'details' => [
                        'event_hash',
                        'previous_hash',
                        'merkle_root',
                        'is_orphaned_genesis',
                    ],
                ],
            ]);

        expect($response->json('data.activity_id'))->toBe($activity->id);
        expect($response->json('data.verification.chain_valid'))->toBeTrue();
    });

    test('enforces tenant isolation for verification', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'activity_log.read');

        $otherTenant = TenantKey::factory()->create();
        $activity = Activity::factory()->create([
            'tenant_id' => $otherTenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/activity-logs/{$activity->id}/verify");

        $response->assertStatus(403);
    });
});
