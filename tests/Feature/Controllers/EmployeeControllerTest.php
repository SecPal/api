<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
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
 * @property OrganizationalUnit $organizationalUnit
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

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    $this->organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
});

afterEach(function (): void {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/employees', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/employees');
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.read permission', function (): void {
        $response = $this->withToken($this->token)->getJson('/v1/employees');
        $response->assertStatus(403);
    });

    test('returns paginated employees with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/employees');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'employee_number', 'first_name', 'last_name', 'email', 'status'],
                ],
                'links',
                'meta',
            ]);
    });

    test('filters employees by status', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'status' => Employee::STATUS_TERMINATED,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees?status=active');

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
    });

    test('filters employees by organizational_unit_id', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        $otherUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
        ]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $otherUnit->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees?organizational_unit_id={$this->organizationalUnit->id}");

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
    });

    test('returns 422 for invalid organizational_unit_id filter format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees?organizational_unit_id=1');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['organizational_unit_id']);
    });

    test('returns 422 for foreign-tenant organizational_unit_id filter', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $foreignUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees?organizational_unit_id={$foreignUnit->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['organizational_unit_id']);
    });

    test('manager with organizational scope cannot list employees outside scope', function (): void {
        $unitA = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
        $unitB = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);

        // Assign Manager role and organizational scope for unitA only
        $this->user->assignRole('Manager');
        $this->user->organizationalScopes()->create([
            'organizational_unit_id' => $unitA->id,
            'access_level' => 'read',
            'include_descendants' => false,
        ]);

        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        // Create employees in both units
        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $unitA->id,
        ]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $unitB->id,
        ]);

        // Manager should only see employee from unitA (scope filtering)
        $response = $this->withToken($this->token)->getJson('/v1/employees');

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['organizational_unit_id'])->toBe($unitA->id);
    });

    test('searches employees by email', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'email' => 'john.doe@example.com',
        ]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'email' => 'jane.smith@example.com',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees?search=john.doe');

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['email'])->toBe('john.doe@example.com');
    });
});

describe('POST /v1/employees', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson('/v1/employees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $response = $this->withToken($this->token)->postJson('/v1/employees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '1990-01-15',
            'status' => 'pre_contract',
            'contract_type' => 'full_time',
            'contract_start_date' => now()->toDateString(),
            'weekly_hours' => 40,
            'hourly_rate' => 15.50,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'management_level' => 0,
        ]);

        $response->assertStatus(403);
    });

    test('returns 422 when required fields are missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'first_name',
                'last_name',
                'email',
                'status',
                'contract_type',
            ]);
    });

    test('creates employee with auto-generated employee_number', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'date_of_birth' => '1990-01-15',
                'status' => 'pre_contract',
                'contract_type' => 'full_time',
                'contract_start_date' => now()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 15.50,
                'organizational_unit_id' => $this->organizationalUnit->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'employee_number',
                    'first_name',
                    'last_name',
                    'email',
                    'status',
                ],
            ]);

        $employeeNumber = $response->json('data.employee_number');
        expect($employeeNumber)->toMatch('/^EMP-\d{4}-\d{4}$/');
        expect($response->json('data.status'))->toBe(Employee::STATUS_PRE_CONTRACT);
    });

    test('creates employee with user account via Observer', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane.smith@example.com',
                'date_of_birth' => '1995-06-20',
                'status' => 'pre_contract',
                'contract_type' => 'full_time',
                'contract_start_date' => now()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 18.00,
                'organizational_unit_id' => $this->organizationalUnit->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
            ]);

        $response->assertStatus(201);

        $employee = Employee::find($response->json('data.id'));
        expect($employee->user_id)->not->toBeNull();
        expect($employee->user->email)->toBe('jane.smith@example.com');
    });

    test('generates unique employee_number per tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response1 = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'First',
                'last_name' => 'Employee',
                'email' => 'first@example.com',
                'date_of_birth' => '1990-01-01',
                'status' => 'pre_contract',
                'contract_type' => 'full_time',
                'contract_start_date' => now()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 15.00,
                'organizational_unit_id' => $this->organizationalUnit->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
            ]);

        $response2 = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'Second',
                'last_name' => 'Employee',
                'email' => 'second@example.com',
                'date_of_birth' => '1992-02-02',
                'status' => 'pre_contract',
                'contract_type' => 'part_time',
                'contract_start_date' => now()->toDateString(),
                'weekly_hours' => 20,
                'hourly_rate' => 16.00,
                'organizational_unit_id' => $this->organizationalUnit->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
            ]);

        $number1 = $response1->json('data.employee_number');
        $number2 = $response2->json('data.employee_number');

        expect($number1)->not->toBe($number2);
        expect($number2)->toMatch('/^EMP-\d{4}-\d{4}$/');
    });
});

describe('GET /v1/employees/{employee}', function () {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
        ]);

        $response = $this->getJson("/v1/employees/{$employee->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.read permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$employee->id}");

        $response->assertStatus(403);
    });

    test('returns employee with relationships', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        // Need TWO scopes: 0-0 for Guards + 1-255 for Leadership (ADR-009)
        $this->user->organizationalScopes()->create([
            'organizational_unit_id' => $this->organizationalUnit->id,
            'access_level' => 'manage',
            'include_descendants' => true,
            'min_viewable_rank' => 0,
            'max_viewable_rank' => 0, // Guards only
            'allow_self_access' => true,
        ]);
        $this->user->organizationalScopes()->create([
            'organizational_unit_id' => $this->organizationalUnit->id,
            'access_level' => 'manage',
            'include_descendants' => true,
            'min_viewable_rank' => 1,
            'max_viewable_rank' => 255, // Leadership only
            'allow_self_access' => true,
        ]);

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'position' => 'Test Position',
            'management_level' => 3,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$employee->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'employee_number',
                    'first_name',
                    'last_name',
                    'email',
                    'status',
                    'position',
                    'management_level',
                    'user',
                    'organizational_unit',
                ],
            ])
            ->assertJson([
                'data' => [
                    'position' => 'Test Position',
                    'management_level' => 3,
                ],
            ]);
    });

    test('returns 404 for invalid employee id format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees/1');

        $response->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
            ]);
    });
});

describe('PATCH /v1/employees/{employee}', function () {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
        ]);

        $response = $this->patchJson("/v1/employees/{$employee->id}", [
            'weekly_hours' => 35,
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'weekly_hours' => 35,
            ]);

        $response->assertStatus(403);
    });

    test('updates employee with valid data', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        // Need TWO scopes: 0-0 for Guards + 1-255 for Leadership (ADR-009)
        $this->user->organizationalScopes()->create([
            'organizational_unit_id' => $this->organizationalUnit->id,
            'access_level' => 'manage',
            'include_descendants' => true,
            'min_viewable_rank' => 0,
            'max_viewable_rank' => 0, // Guards only
            'allow_self_access' => true,
        ]);
        $this->user->organizationalScopes()->create([
            'organizational_unit_id' => $this->organizationalUnit->id,
            'access_level' => 'manage',
            'include_descendants' => true,
            'min_viewable_rank' => 1,
            'max_viewable_rank' => 255, // Leadership only
            'allow_self_access' => true,
        ]);

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'weekly_hours' => 40,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'weekly_hours' => 35,
            ]);

        $response->assertStatus(200);
        expect($response->json('data.weekly_hours'))->toBe('35.00'); // decimal:2 cast returns string
    });
});

describe('DELETE /v1/employees/{employee}', function () {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
        ]);

        $response = $this->deleteJson("/v1/employees/{$employee->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/employees/{$employee->id}");

        $response->assertStatus(403);
    });

    test('soft deletes employee with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/employees/{$employee->id}");

        $response->assertStatus(204);
        expect(Employee::withTrashed()->find($employee->id)->deleted_at)->not->toBeNull();
    });
});

describe('POST /v1/employees/{employee}/activate', function () {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $response = $this->postJson("/v1/employees/{$employee->id}/activate");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate");

        $response->assertStatus(403);
    });

    test('activates pre-contract employee with valid conditions', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => true,
            'contract_start_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate");

        $response->assertStatus(200);
        expect($response->json('data.status'))->toBe(Employee::STATUS_ACTIVE);
    });

    test('returns 422 when onboarding not completed', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
            'contract_start_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate");

        $response->assertStatus(422);
    });

    test('returns 422 when contract_start_date is future', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => true,
            'contract_start_date' => now()->addWeek()->toDateString(),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate");

        $response->assertStatus(422);
    });
});

describe('POST /v1/employees/{employee}/terminate', function () {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->postJson("/v1/employees/{$employee->id}/terminate", [
            'termination_date' => now()->toDateString(),
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/terminate", [
                'termination_date' => now()->toDateString(),
            ]);

        $response->assertStatus(403);
    });

    test('terminates active employee with valid data', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/terminate", [
                'termination_date' => now()->toDateString(),
                'termination_reason' => 'resignation',
            ]);

        $response->assertStatus(200);
        expect($response->json('data.status'))->toBe(Employee::STATUS_TERMINATED);
    });

    test('returns 422 when terminating pre-contract employee', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->organizationalUnit->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/terminate", [
                'termination_date' => now()->toDateString(),
            ]);

        $response->assertStatus(422);
    });
});

test('manager cannot create employee in unit outside their scope', function (): void {
    $unitA = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $unitB = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);

    // Manager has scope only on unitA
    $this->user->assignRole('Manager');
    $this->user->organizationalScopes()->create([
        'organizational_unit_id' => $unitA->id,
        'access_level' => 'write',
        'include_descendants' => false,
    ]);

    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

    // Attempt to create employee in unitB (outside scope)
    $response = $this->withToken($this->token)->postJson('/v1/employees', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe@example.com',
        'date_of_birth' => '1990-01-15',
        'status' => 'pre_contract',
        'contract_type' => 'full_time',
        'contract_start_date' => now()->toDateString(),
        'position' => 'Security Guard',
        'organizational_unit_id' => $unitB->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['organizational_unit_id']);
    expect($response->json('errors.organizational_unit_id.0'))->toContain('do not have access');
});

test('manager can create employee in unit within their scope', function (): void {
    $unitA = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);

    // Manager has scope on unitA
    $this->user->assignRole('Manager');
    $this->user->organizationalScopes()->create([
        'organizational_unit_id' => $unitA->id,
        'access_level' => 'write',
        'include_descendants' => false,
    ]);

    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

    // Create employee in unitA (within scope)
    $response = $this->withToken($this->token)->postJson('/v1/employees', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe@example.com',
        'date_of_birth' => '1990-01-15',
        'status' => 'pre_contract',
        'contract_type' => 'full_time',
        'contract_start_date' => now()->toDateString(),
        'position' => 'Security Guard',
        'organizational_unit_id' => $unitA->id,
        'management_level' => 0,
    ]);

    $response->assertStatus(201);
    expect($response->json('data.organizational_unit_id'))->toBe($unitA->id);
});

test('manager cannot move employee to unit outside their scope', function (): void {
    $unitA = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $unitB = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);

    // Manager has scope only on unitA
    $this->user->assignRole('Manager');
    $this->user->organizationalScopes()->create([
        'organizational_unit_id' => $unitA->id,
        'access_level' => 'write',
        'include_descendants' => false,
    ]);

    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

    // Create employee in unitA
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $unitA->id,
    ]);

    // Attempt to move employee to unitB (outside scope)
    $response = $this->withToken($this->token)->patchJson("/v1/employees/{$employee->id}", [
        'organizational_unit_id' => $unitB->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['organizational_unit_id']);
    expect($response->json('errors.organizational_unit_id.0'))->toContain('do not have access');
});

test('admin without organizational scopes can create employee in any unit', function (): void {
    $unitA = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);

    // Admin has no organizational scopes (unrestricted access)
    $this->user->assignRole('Admin');
    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

    $response = $this->withToken($this->token)->postJson('/v1/employees', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe@example.com',
        'date_of_birth' => '1990-01-15',
        'status' => 'pre_contract',
        'contract_type' => 'full_time',
        'contract_start_date' => now()->toDateString(),
        'position' => 'Security Guard',
        'organizational_unit_id' => $unitA->id,
        'management_level' => 0,
    ]);

    $response->assertStatus(201);
});

test('manager with include_descendants=true can create employee in child unit', function (): void {
    $parent = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $child = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $child->setParent($parent);

    // Manager has scope on parent with include_descendants=true
    $this->user->assignRole('Manager');
    $this->user->organizationalScopes()->create([
        'organizational_unit_id' => $parent->id,
        'access_level' => 'write',
        'include_descendants' => true,
    ]);

    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

    // Create employee in child unit
    $response = $this->withToken($this->token)->postJson('/v1/employees', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe@example.com',
        'date_of_birth' => '1990-01-15',
        'status' => 'pre_contract',
        'contract_type' => 'full_time',
        'contract_start_date' => now()->toDateString(),
        'position' => 'Security Guard',
        'organizational_unit_id' => $child->id,
        'management_level' => 0,
    ]);

    $response->assertStatus(201);
    expect($response->json('data.organizational_unit_id'))->toBe($child->id);
});

test('manager with include_descendants=false cannot create employee in child unit', function (): void {
    $parent = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $child = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $child->setParent($parent);

    // Manager has scope on parent with include_descendants=false
    $this->user->assignRole('Manager');
    $this->user->organizationalScopes()->create([
        'organizational_unit_id' => $parent->id,
        'access_level' => 'write',
        'include_descendants' => false,
    ]);

    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

    // Attempt to create employee in child unit
    $response = $this->withToken($this->token)->postJson('/v1/employees', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe@example.com',
        'date_of_birth' => '1990-01-15',
        'status' => 'pre_contract',
        'contract_type' => 'full_time',
        'contract_start_date' => now()->toDateString(),
        'position' => 'Security Guard',
        'organizational_unit_id' => $child->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['organizational_unit_id']);
});

test('manager with scope on parent can list employees from all descendant units', function (): void {
    $parent = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $child1 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $child1->setParent($parent);
    $child2 = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $child2->setParent($parent);

    // Manager has scope on parent with include_descendants=true
    $this->user->assignRole('Manager');
    $this->user->organizationalScopes()->create([
        'organizational_unit_id' => $parent->id,
        'access_level' => 'read',
        'include_descendants' => true,
    ]);

    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

    // Create employees in parent and child units
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $parent->id,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $child1->id,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $child2->id,
    ]);

    // Create employee in unrelated unit (should not be visible)
    $unrelatedUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $unrelatedUnit->id,
    ]);

    $response = $this->withToken($this->token)->getJson('/v1/employees');

    $response->assertStatus(200);
    // Should see 3 employees (parent + 2 children), not the unrelated one
    expect($response->json('data'))->toHaveCount(3);
});
