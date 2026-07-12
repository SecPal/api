<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use App\Models\Customer;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property string $token
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

    // Create test customer and site
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $customer->id,
    ]);
});

afterEach(function (): void {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/sites/{site}/assignments', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson("/v1/sites/{$this->site->id}/assignments");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks sites.view permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson("/v1/sites/{$this->site->id}/assignments");
        $response->assertStatus(403);
    });

    test('returns paginated assignments with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        Carbon::setTestNow(Carbon::parse('2026-05-30 12:34:56 UTC'));

        $targetUser = User::factory()->create();

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
            'role' => 'Site Manager',
        ]);
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
            'role' => 'Operations Lead',
        ]);
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
            'role' => 'Quality Manager',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/sites/{$this->site->id}/assignments");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'role', 'is_active', 'valid_from', 'valid_until', 'notes', 'user', 'site', 'created_at', 'updated_at'],
                ],
            ])
            ->assertJsonPath('data.0.created_at', '2026-05-30T12:34:56Z')
            ->assertJsonPath('data.0.updated_at', '2026-05-30T12:34:56Z')
            ->assertJsonPath('data.0.user.created_at', '2026-05-30T12:34:56Z')
            ->assertJsonPath('data.0.user.updated_at', '2026-05-30T12:34:56Z');

        Carbon::setTestNow();
    });

    test('filters assignments by role', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $targetUser = User::factory()->create();

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
            'role' => 'Site Manager',
        ]);

        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
            'role' => 'Security Officer',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/sites/{$this->site->id}/assignments?role=Site Manager");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['role'])->toBe('Site Manager');
    });

    test('returns 422 when role filter is not a string', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $response = $this->withToken($this->token)
            ->getJson("/v1/sites/{$this->site->id}/assignments?role[0]=Site%20Manager");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    });

    test('filters assignments by active status', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $targetUser = User::factory()->create();

        // Active assignment
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
            'role' => 'Active Site Manager',
            'valid_from' => null,
            'valid_until' => null,
        ]);

        // Expired assignment
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
            'role' => 'Former Site Manager',
            'valid_until' => now()->subDay(),
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/sites/{$this->site->id}/assignments?active_only=1");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['is_active'])->toBeTrue();
    });

    test('returns preserved site assignment history when the linked user was deleted', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.read');

        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => null,
            'role' => 'Former Site Manager',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/sites/{$this->site->id}/assignments");

        $response->assertOk()
            ->assertJsonPath('data.0.id', $assignment->id)
            ->assertJsonPath('data.0.user', null);
    });
});

describe('POST /v1/sites/{site}/assignments', function () {
    test('rejects assignments for a site in a closed organizational unit', function (bool $isDeleted): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $organizationalUnit = OrganizationalUnit::query()->findOrFail($this->site->organizational_unit_id);
        $isDeleted ? $organizationalUnit->delete() : $organizationalUnit->update(['is_assignable' => false]);
        $targetUser = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => 'Site Manager',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['organizational_unit_id']);

        $this->assertDatabaseMissing('site_assignments', [
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
            'role' => 'Site Manager',
        ]);
    })->with(['deleted' => true, 'non-assignable' => false]);

    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson("/v1/sites/{$this->site->id}/assignments", []);
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks sites.assign.users permission', function (): void {
        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => 'Manager',
            ]);
        $response->assertStatus(403);
    });

    test('creates site assignment with valid data', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $targetUser = User::factory()->create();

        $data = [
            'user_id' => $targetUser->id,
            'role' => 'Site Manager',
            'notes' => 'Responsible for daily operations',
        ];

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", $data);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'role', 'user', 'site']]);

        expect($response->json('data.role'))->toBe('Site Manager');
        expect($response->json('data.notes'))->toBe('Responsible for daily operations');
        expect($response->json('data.user.id'))->toBe($targetUser->id);
    });

    test('returns 422 when target user belongs to a different tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $foreignUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'user_id' => $foreignUser->id,
                'role' => 'Cross Tenant Site Manager',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    });

    test('creates assignment with validity period', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $targetUser = User::factory()->create();

        $data = [
            'user_id' => $targetUser->id,
            'role' => 'Temporary Inspector',
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addWeeks(2)->toDateString(),
        ];

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", $data);

        $response->assertStatus(201);
        expect($response->json('data.valid_from'))->not()->toBeNull();
        expect($response->json('data.valid_until'))->not()->toBeNull();
    });

    test('blocks assignments when the target user employee has critical compliance expiries', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $targetUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $organizationalUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Employee::factory()->withExpiringComplianceCertifications()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $organizationalUnit->id,
            'user_id' => $targetUser->id,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => 'Site Manager',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Employee cannot be assigned while critical compliance documents are expired or due within 7 days.')
            ->assertJsonStructure([
                'blocking_documents' => [
                    '*' => ['type', 'status', 'expiry'],
                ],
            ]);
    });

    test('allows assignments when the target user employee has warning level compliance alerts only', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $targetUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $organizationalUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Employee::factory()->withComplianceCertifications()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $organizationalUnit->id,
            'user_id' => $targetUser->id,
            'firearms_license_expiry' => now()->addDays(20)->toDateString(),
            'first_aid_cert_expiry' => now()->addDays(45)->toDateString(),
            'evacuation_cert_expiry' => now()->addDays(60)->toDateString(),
            'additional_certifications' => [
                [
                    'name' => 'Badge',
                    'issued_date' => now()->subMonth()->toDateString(),
                    'expiry_date' => now()->addDays(21)->toDateString(),
                    'issuer' => 'Customer Security',
                ],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => 'Site Manager',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.id', $targetUser->id);
    });

    test('returns 409 when duplicate assignment exists', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $targetUser = User::factory()->create();

        // Create existing assignment
        SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
            'role' => 'Site Manager',
        ]);

        // Attempt duplicate
        $data = [
            'user_id' => $targetUser->id,
            'role' => 'Site Manager',
        ];

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", $data);

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Assignment already exists for this user and role',
            ]);
    });

    test('validates user_id is required', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'role' => 'Manager',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    });

    test('validates role is required', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'user_id' => $targetUser->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    });

    test('validates user_id must exist', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'user_id' => '00000000-0000-0000-0000-000000000000',
                'role' => 'Manager',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    });

    test('validates role max length 100 characters', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => str_repeat('A', 101),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    });

    test('validates notes max length 1000 characters', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => 'Manager',
                'notes' => str_repeat('A', 1001),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    });

    test('validates valid_from must be date format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => 'Manager',
                'valid_from' => 'not-a-date',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from']);
    });

    test('validates valid_until must be date format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/sites/{$this->site->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => 'Manager',
                'valid_until' => 'not-a-date',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);
    });
});

describe('PATCH /v1/site-assignments/{assignment}', function () {
    test('rejects reactivating an assignment in a non-assignable organizational unit', function (string $scenario): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.update');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');

        OrganizationalUnit::query()
            ->findOrFail($this->site->organizational_unit_id)
            ->update(['is_assignable' => false]);

        [$initialDates, $updatedDates] = match ($scenario) {
            'future start' => [
                ['valid_from' => now()->addDay(), 'valid_until' => now()->addWeek()],
                ['valid_from' => now()->toDateString()],
            ],
            'future window' => [
                ['valid_until' => now()->subDay()],
                ['valid_from' => now()->addDay()->toDateString(), 'valid_until' => now()->addWeek()->toDateString()],
            ],
            'current window' => [
                ['valid_until' => now()->subDay()],
                ['valid_until' => now()->addWeek()->toDateString()],
            ],
            'active window' => [
                ['valid_until' => now()->toDateString()],
                ['valid_until' => now()->addWeek()->toDateString()],
            ],
            'scheduled extension' => [
                ['valid_from' => now()->addWeek(), 'valid_until' => now()->addWeeks(2)],
                ['valid_until' => now()->addYear()->toDateString()],
            ],
            'scheduled earlier start' => [
                ['valid_from' => now()->addWeeks(2), 'valid_until' => now()->addWeeks(3)],
                ['valid_from' => now()->addWeek()->toDateString()],
            ],
            'active role change' => [
                ['role' => 'Site Manager'],
                ['role' => 'Operations Lead'],
            ],
        };

        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
            ...$initialDates,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/site-assignments/{$assignment->id}", $updatedDates);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['organizational_unit_id']);
    })->with(['future start', 'future window', 'current window', 'active window', 'scheduled extension', 'scheduled earlier start', 'active role change']);

    test('allows correcting past-only assignment coverage in a non-assignable organizational unit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.update');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        OrganizationalUnit::query()
            ->findOrFail($this->site->organizational_unit_id)
            ->update(['is_assignable' => false]);

        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'valid_from' => now()->subWeeks(3),
            'valid_until' => now()->subWeeks(2),
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/site-assignments/{$assignment->id}", [
                'valid_until' => now()->subWeek()->toDateString(),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.valid_until', now()->subWeek()->toDateString());
    });

    test('allows changing a role while ending all assignment coverage in a non-assignable organizational unit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.update');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        OrganizationalUnit::query()
            ->findOrFail($this->site->organizational_unit_id)
            ->update(['is_assignable' => false]);

        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'role' => 'Supervisor',
            'valid_until' => null,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/site-assignments/{$assignment->id}", [
                'role' => 'Former Supervisor',
                'valid_until' => now()->subDay()->toDateString(),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.role', 'Former Supervisor')
            ->assertJsonPath('data.valid_until', now()->subDay()->toDateString());
    });

    test('returns 401 when not authenticated', function (): void {
        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->patchJson("/v1/site-assignments/{$assignment->id}", []);
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks assignments.update permission', function (): void {
        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/site-assignments/{$assignment->id}", []);
        $response->assertStatus(403);
    });

    test('updates assignment role', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.update');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        $targetUser = User::factory()->create();
        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
            'role' => 'Old Role',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/site-assignments/{$assignment->id}", [
                'role' => 'New Role',
            ]);

        $response->assertOk();
        expect($response->json('data.role'))->toBe('New Role');
    });

    test('updates assignment notes', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.update');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        $targetUser = User::factory()->create();
        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/site-assignments/{$assignment->id}", [
                'notes' => 'Updated notes',
            ]);

        $response->assertOk();
        expect($response->json('data.notes'))->toBe('Updated notes');
    });

    test('updates validity period', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.update');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        $targetUser = User::factory()->create();
        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
        ]);

        $newFrom = now()->addDay()->toDateString();
        $newUntil = now()->addWeeks(4)->toDateString();

        $response = $this->withToken($this->token)
            ->patchJson("/v1/site-assignments/{$assignment->id}", [
                'valid_from' => $newFrom,
                'valid_until' => $newUntil,
            ]);

        $response->assertOk();
        expect($response->json('data.valid_from'))->toContain($newFrom);
        expect($response->json('data.valid_until'))->toContain($newUntil);
    });

    test('allows partial updates (PATCH semantics)', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.update');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        $targetUser = User::factory()->create();
        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
            'role' => 'Original Role',
            'notes' => 'Original Notes',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/site-assignments/{$assignment->id}", [
                'notes' => 'Only notes updated',
            ]);

        $response->assertOk();
        expect($response->json('data.role'))->toBe('Original Role');
        expect($response->json('data.notes'))->toBe('Only notes updated');
    });
});

describe('DELETE /v1/site-assignments/{assignment}', function () {
    test('returns 401 when not authenticated', function (): void {
        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->deleteJson("/v1/site-assignments/{$assignment->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks assignments.delete permission', function (): void {
        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/site-assignments/{$assignment->id}");
        $response->assertStatus(403);
    });

    test('deletes assignment permanently', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.delete');
        givePermissionWithTenant($this->user, $this->tenant->id, 'sites.update');
        $targetUser = User::factory()->create();
        $assignment = SiteAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'user_id' => $targetUser->id,
        ]);

        $assignmentId = $assignment->id;

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/site-assignments/{$assignmentId}");

        $response->assertNoContent();

        expect(SiteAssignment::find($assignmentId))->toBeNull();
    });
});
