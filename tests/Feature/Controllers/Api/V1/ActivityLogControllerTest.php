<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
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

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * @return array{tenant: TenantKey, registrar: PermissionRegistrar, user: User}
 */
function createActivityLogContext(): array
{
    $keys = TenantKey::generateEnvelopeKeys();
    $tenant = TenantKey::create($keys);

    /** @var PermissionRegistrar $registrar */
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    return [
        'tenant' => $tenant,
        'registrar' => $registrar,
        'user' => $user,
    ];
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/activity-logs', function () {
    test('returns 401 when not authenticated', function (): void {
        createActivityLogContext();

        $response = getJson('/v1/activity-logs');
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks activity_log.read permission', function (): void {
        ['user' => $user] = createActivityLogContext();

        actingAs($user, 'sanctum');

        $response = getJson('/v1/activity-logs');
        $response->assertStatus(403);
    });

    test('returns empty list when user has permission but no accessible logs', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $response = getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toBeArray();
        expect($response->json('data'))->toHaveCount(0);
    });

    test('returns global activities (no org unit) to any user with permission', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
            'log_name' => 'authentication',
            'description' => 'User logged in',
        ]);

        $response = getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['log_name'])->toBe('authentication');
    });

    test('returns activities from accessible organizational units', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Department A',
        ]);

        UserInternalOrganizationalScope::factory()->create([
            'user_id' => $user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'min_viewable_rank' => null,
            'max_viewable_rank' => null,
        ]);

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => $orgUnit->id,
            'log_name' => 'employee_changes',
            'description' => 'Employee updated',
        ]);

        $response = getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['organizational_unit_id'])->toBe($orgUnit->id);
    });

    test('excludes activities from inaccessible organizational units', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $accessibleUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $inaccessibleUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        UserInternalOrganizationalScope::factory()->create([
            'user_id' => $user->id,
            'organizational_unit_id' => $accessibleUnit->id,
            'access_level' => 'read',
            'min_viewable_rank' => null,
            'max_viewable_rank' => null,
        ]);

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => $accessibleUnit->id,
            'description' => 'Accessible activity',
        ]);

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => $inaccessibleUnit->id,
            'description' => 'Inaccessible activity',
        ]);

        $response = getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toBe('Accessible activity');
    });

    test('excludes global activities for users with organizational scopes', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        UserInternalOrganizationalScope::factory()->create([
            'user_id' => $user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'min_viewable_rank' => null,
            'max_viewable_rank' => null,
        ]);

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
            'description' => 'Global activity',
        ]);

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => $orgUnit->id,
            'description' => 'Scoped activity',
        ]);

        $response = getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toBe('Scoped activity');
    });

    test('filters by leadership level - only shows subordinates activities', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        UserInternalOrganizationalScope::factory()->create([
            'user_id' => $user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'min_viewable_rank' => 2,
            'max_viewable_rank' => 5,
        ]);

        $seniorManager = User::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $seniorManager->id,
            'organizational_unit_id' => $orgUnit->id,
            'management_level' => 1,
        ]);

        $subordinate = User::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $subordinate->id,
            'organizational_unit_id' => $orgUnit->id,
            'management_level' => 3,
        ]);

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => $orgUnit->id,
            'causer_type' => User::class,
            'causer_id' => $seniorManager->id,
            'description' => 'Senior manager activity',
        ]);

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => $orgUnit->id,
            'causer_type' => User::class,
            'causer_id' => $subordinate->id,
            'description' => 'Subordinate activity',
        ]);

        $response = getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toBe('Subordinate activity');
    });

    test('shows system-generated activities regardless of leadership level', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        UserInternalOrganizationalScope::factory()->create([
            'user_id' => $user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'min_viewable_rank' => 2,
            'max_viewable_rank' => 5,
        ]);

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => $orgUnit->id,
            'causer_type' => null,
            'causer_id' => null,
            'description' => 'System activity',
        ]);

        $response = getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toBe('System activity');
    });

    test('filters by date range', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
            'description' => 'Old activity',
            'created_at' => now()->subDays(10),
        ]);

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
            'description' => 'Recent activity',
            'created_at' => now()->subDays(2),
        ]);

        $response = getJson('/v1/activity-logs?from_date='.now()->subDays(3)->toDateString());

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toBe('Recent activity');
    });

    test('filters by log_name', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
            'log_name' => 'authentication',
            'description' => 'User logged in',
        ]);

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
            'log_name' => 'hr_access',
            'description' => 'HR data accessed',
        ]);

        $response = getJson('/v1/activity-logs?log_name=hr_access');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['log_name'])->toBe('hr_access');
    });

    test('searches in description', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
            'description' => 'Employee contract updated',
        ]);

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
            'description' => 'User logged out',
        ]);

        $response = getJson('/v1/activity-logs?search=contract');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toContain('contract');
    });

    test('respects pagination parameters', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        Activity::factory()->count(15)->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = getJson('/v1/activity-logs?per_page=5');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(5);
        expect($response->json('meta.per_page'))->toBe(5);
        expect($response->json('meta.total'))->toBe(15);
    });

    test('enforces tenant isolation', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
            'description' => 'Own tenant activity',
        ]);

        $otherTenant = TenantKey::factory()->create();
        Activity::factory()->create([
            'tenant_id' => $otherTenant->id,
            'organizational_unit_id' => null,
            'description' => 'Other tenant activity',
        ]);

        $response = getJson('/v1/activity-logs');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['description'])->toBe('Own tenant activity');
    });
});

describe('GET /v1/activity-logs/{activity}', function () {
    test('returns 401 when not authenticated', function (): void {
        ['tenant' => $tenant] = createActivityLogContext();

        $activity = Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = getJson("/v1/activity-logs/{$activity->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks activity_log.read permission', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        $activity = Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
        ]);

        actingAs($user, 'sanctum');

        $response = getJson("/v1/activity-logs/{$activity->id}");

        $response->assertStatus(403);
    });

    test('returns 404 when user tries to access activity from different tenant', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $otherTenant = TenantKey::factory()->create();
        $activity = Activity::factory()->create([
            'tenant_id' => $otherTenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = getJson("/v1/activity-logs/{$activity->id}");

        $response->assertNotFound();
    });

    test('returns 404 for a non-existent activity', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $response = getJson('/v1/activity-logs/1');

        $response->assertNotFound()
            ->assertJson(['message' => 'Resource not found.']);
    });

    test('returns global activity when user has permission', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $activity = Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
            'log_name' => 'authentication',
            'description' => 'User logged in',
        ]);

        $response = getJson("/v1/activity-logs/{$activity->id}");

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
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        UserInternalOrganizationalScope::factory()->create([
            'user_id' => $user->id,
            'organizational_unit_id' => $orgUnit->id,
            'access_level' => 'read',
            'min_viewable_rank' => null,
            'max_viewable_rank' => null,
        ]);

        $activity = Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => $orgUnit->id,
        ]);

        $response = getJson("/v1/activity-logs/{$activity->id}");

        $response->assertOk();
        expect($response->json('data.id'))->toBe($activity->id);
    });

    test('returns 403 for activity in inaccessible organizational unit', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $inaccessibleUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $activity = Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => $inaccessibleUnit->id,
        ]);

        $response = getJson("/v1/activity-logs/{$activity->id}");

        $response->assertStatus(403);
    });

    test('includes verification data when requested', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $activity = Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
        ]);

        $activity->refresh();

        $response = getJson("/v1/activity-logs/{$activity->id}?include_verification=1");

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
        ['tenant' => $tenant] = createActivityLogContext();

        $activity = Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = getJson("/v1/activity-logs/{$activity->id}/verify");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks activity_log.read permission', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        $activity = Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
        ]);

        actingAs($user, 'sanctum');

        $response = getJson("/v1/activity-logs/{$activity->id}/verify");

        $response->assertStatus(403);
    });

    test('returns verification results for accessible activity', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $activity = Activity::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => null,
            'log_name' => 'authentication',
        ]);

        $activity->refresh();

        $response = getJson("/v1/activity-logs/{$activity->id}/verify");

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

    test('returns 404 for verification when activity belongs to different tenant', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $otherTenant = TenantKey::factory()->create();
        $activity = Activity::factory()->create([
            'tenant_id' => $otherTenant->id,
            'organizational_unit_id' => null,
        ]);

        $response = getJson("/v1/activity-logs/{$activity->id}/verify");

        $response->assertNotFound();
    });

    test('returns 404 for verification of a non-existent activity', function (): void {
        ['tenant' => $tenant, 'user' => $user] = createActivityLogContext();

        givePermissionWithTenant($user, $tenant->id, 'activity_log.read');
        actingAs($user, 'sanctum');

        $response = getJson('/v1/activity-logs/1/verify');

        $response->assertNotFound()
            ->assertJson(['message' => 'Resource not found.']);
    });
});
