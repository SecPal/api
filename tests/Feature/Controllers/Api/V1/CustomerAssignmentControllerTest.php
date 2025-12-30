<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * @property \App\Models\TenantKey $tenant
 * @property \App\Models\User $user
 * @property string $token
 * @property \App\Models\Customer $customer
 */
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

    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    // Create test customer
    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
});

afterEach(function (): void {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/customers/{customer}/assignments', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson("/v1/customers/{$this->customer->id}/assignments");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks customers.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson("/v1/customers/{$this->customer->id}/assignments");
        $response->assertStatus(403);
    });

    test('returns paginated assignments with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $targetUser = User::factory()->create();

        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
            'role' => 'Account Manager',
        ]);
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
            'role' => 'Billing Contact',
        ]);
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
            'role' => 'Technical Contact',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/customers/{$this->customer->id}/assignments");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'role', 'is_active', 'valid_from', 'valid_until', 'notes', 'user', 'customer', 'created_at', 'updated_at'],
                ],
            ]);
    });

    test('filters assignments by role', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $targetUser = User::factory()->create();

        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
            'role' => 'Key Account Manager',
        ]);

        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
            'role' => 'Support Coordinator',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/customers/{$this->customer->id}/assignments?role=Key Account Manager");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['role'])->toBe('Key Account Manager');
    });

    test('filters assignments by active status', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');

        $targetUser = User::factory()->create();

        // Active assignment (no dates)
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
            'role' => 'Active Account Manager',
            'valid_from' => null,
            'valid_until' => null,
        ]);

        // Expired assignment
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
            'role' => 'Former Account Manager',
            'valid_until' => now()->subDay(),
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/customers/{$this->customer->id}/assignments?active_only=1");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['is_active'])->toBeTrue();
    });
});

describe('POST /v1/customers/{customer}/assignments', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson("/v1/customers/{$this->customer->id}/assignments", []);
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks assignments.create permission', function (): void {
        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/customers/{$this->customer->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => 'Manager',
            ]);
        $response->assertStatus(403);
    });

    test('creates customer assignment with valid data', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();

        $data = [
            'user_id' => $targetUser->id,
            'role' => 'Key Account Manager',
            'notes' => 'Primary account contact',
        ];

        $response = $this->withToken($this->token)
            ->postJson("/v1/customers/{$this->customer->id}/assignments", $data);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'role', 'user', 'customer']]);

        expect($response->json('data.role'))->toBe('Key Account Manager');
        expect($response->json('data.notes'))->toBe('Primary account contact');
        expect($response->json('data.user.id'))->toBe($targetUser->id);
    });

    test('creates assignment with validity period', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();

        $data = [
            'user_id' => $targetUser->id,
            'role' => 'Temporary Consultant',
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addMonths(6)->toDateString(),
        ];

        $response = $this->withToken($this->token)
            ->postJson("/v1/customers/{$this->customer->id}/assignments", $data);

        $response->assertStatus(201);
        expect($response->json('data.valid_from'))->not()->toBeNull();
        expect($response->json('data.valid_until'))->not()->toBeNull();
    });

    test('returns 409 when duplicate assignment exists', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();

        // Create existing assignment
        CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
            'role' => 'Key Account Manager',
        ]);

        // Attempt duplicate
        $data = [
            'user_id' => $targetUser->id,
            'role' => 'Key Account Manager',
        ];

        $response = $this->withToken($this->token)
            ->postJson("/v1/customers/{$this->customer->id}/assignments", $data);

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Assignment already exists for this user and role',
            ]);
    });

    test('validates user_id is required', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $response = $this->withToken($this->token)
            ->postJson("/v1/customers/{$this->customer->id}/assignments", [
                'role' => 'Manager',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    });

    test('validates role is required', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/customers/{$this->customer->id}/assignments", [
                'user_id' => $targetUser->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    });

    test('validates user_id must exist', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $response = $this->withToken($this->token)
            ->postJson("/v1/customers/{$this->customer->id}/assignments", [
                'user_id' => '00000000-0000-0000-0000-000000000000',
                'role' => 'Manager',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    });

    test('validates role max length 100 characters', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/customers/{$this->customer->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => str_repeat('A', 101),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    });

    test('validates notes max length 1000 characters', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/customers/{$this->customer->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => 'Manager',
                'notes' => str_repeat('A', 1001),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    });

    test('validates valid_from must be date format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/customers/{$this->customer->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => 'Manager',
                'valid_from' => 'not-a-date',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from']);
    });

    test('validates valid_until must be date format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.create');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();

        $response = $this->withToken($this->token)
            ->postJson("/v1/customers/{$this->customer->id}/assignments", [
                'user_id' => $targetUser->id,
                'role' => 'Manager',
                'valid_until' => 'not-a-date',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);
    });
});

describe('PATCH /v1/customer-assignments/{assignment}', function () {
    test('returns 401 when not authenticated', function (): void {
        $assignment = CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
        ]);

        $response = $this->patchJson("/v1/customer-assignments/{$assignment->id}", []);
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks assignments.update permission', function (): void {
        $assignment = CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/customer-assignments/{$assignment->id}", []);
        $response->assertStatus(403);
    });

    test('updates assignment role', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.update');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();
        $assignment = CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
            'role' => 'Old Role',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/customer-assignments/{$assignment->id}", [
                'role' => 'New Role',
            ]);

        $response->assertOk();
        expect($response->json('data.role'))->toBe('New Role');
    });

    test('updates assignment notes', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.update');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();
        $assignment = CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/customer-assignments/{$assignment->id}", [
                'notes' => 'Updated notes',
            ]);

        $response->assertOk();
        expect($response->json('data.notes'))->toBe('Updated notes');
    });

    test('updates validity period', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.update');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();
        $assignment = CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
        ]);

        $newFrom = now()->addDay()->toDateString();
        $newUntil = now()->addMonths(3)->toDateString();

        $response = $this->withToken($this->token)
            ->patchJson("/v1/customer-assignments/{$assignment->id}", [
                'valid_from' => $newFrom,
                'valid_until' => $newUntil,
            ]);

        $response->assertOk();
        expect($response->json('data.valid_from'))->toContain($newFrom);
        expect($response->json('data.valid_until'))->toContain($newUntil);
    });

    test('allows partial updates (PATCH semantics)', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.update');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();
        $assignment = CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
            'role' => 'Original Role',
            'notes' => 'Original Notes',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/customer-assignments/{$assignment->id}", [
                'notes' => 'Only notes updated',
            ]);

        $response->assertOk();
        expect($response->json('data.role'))->toBe('Original Role');
        expect($response->json('data.notes'))->toBe('Only notes updated');
    });
});

describe('DELETE /v1/customer-assignments/{assignment}', function () {
    test('returns 401 when not authenticated', function (): void {
        $assignment = CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
        ]);

        $response = $this->deleteJson("/v1/customer-assignments/{$assignment->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks assignments.delete permission', function (): void {
        $assignment = CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/customer-assignments/{$assignment->id}");
        $response->assertStatus(403);
    });

    test('deletes assignment permanently', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'assignments.delete');
        givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');

        $targetUser = User::factory()->create();
        $assignment = CustomerAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'user_id' => $targetUser->id,
        ]);

        $assignmentId = $assignment->id;

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/customer-assignments/{$assignmentId}");

        $response->assertStatus(204);

        expect(CustomerAssignment::find($assignmentId))->toBeNull();
    });
});
