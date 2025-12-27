<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property mixed $token
 * @property Employee $employee
 * @property Qualification $qualification
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

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    $organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $organizationalUnit->id,
    ]);

    // Give organizational scope for testing
    giveOrganizationalScope($this->user, $organizationalUnit);

    $this->qualification = Qualification::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
});

afterEach(function (): void {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/employees/{employee}/qualifications', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson("/v1/employees/{$this->employee->id}/qualifications");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee_qualification.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/qualifications");

        $response->assertStatus(403);
    });

    test('returns employee qualifications with relationships', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_qualification.read');

        $this->employee->qualifications()->attach($this->qualification->id, [
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'obtained_date' => now()->toDateString(),
            'status' => 'valid',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/qualifications");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'employee_id',
                        'qualification_id',
                        'obtained_date',
                        'status',
                        'employee',
                        'qualification',
                    ],
                ],
            ]);

        expect($response->json('data'))->toHaveCount(1);
    });

    test('manager with organizational scope cannot list qualifications of employee outside scope', function (): void {
        $unitA = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
        $unitB = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);

        // Create manager with scope for unitA only
        $manager = User::factory()->create();
        $managerToken = $manager->createToken('test-device')->plainTextToken;
        $manager->assignRole('Manager');
        $manager->organizationalScopes()->create([
            'organizational_unit_id' => $unitA->id,
            'access_level' => 'read',
            'include_descendants' => false,
        ]);

        givePermissionWithTenant($manager, $this->tenant->id, 'employee_qualification.read');

        // Employee in unitA (accessible)
        $employeeA = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $unitA->id,
        ]);

        // Employee in unitB (not accessible)
        $employeeB = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $unitB->id,
        ]);

        $employeeA->qualifications()->attach($this->qualification->id, [
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'obtained_date' => now()->toDateString(),
            'status' => 'valid',
        ]);

        $employeeB->qualifications()->attach($this->qualification->id, [
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'obtained_date' => now()->toDateString(),
            'status' => 'valid',
        ]);

        // Manager can access qualifications of employeeA
        $responseA = $this->withToken($managerToken)->getJson("/v1/employees/{$employeeA->id}/qualifications");
        $responseA->assertStatus(200);
        expect($responseA->json('data'))->toHaveCount(1);

        // Manager cannot access qualifications of employeeB (outside scope)
        $responseB = $this->withToken($managerToken)->getJson("/v1/employees/{$employeeB->id}/qualifications");
        $responseB->assertStatus(403);
    });
});

describe('POST /v1/employees/{employee}/qualifications', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson("/v1/employees/{$this->employee->id}/qualifications", [
            'qualification_id' => $this->qualification->id,
            'obtained_date' => now()->toDateString(),
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee_qualification.write permission', function (): void {
        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/qualifications", [
                'qualification_id' => $this->qualification->id,
                'obtained_date' => now()->toDateString(),
            ]);

        $response->assertStatus(403);
    });

    test('returns 422 when required fields are missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_qualification.write');

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/qualifications", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['qualification_id', 'obtained_date']);
    });

    test('attaches qualification to employee with valid data', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_qualification.write');

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/qualifications", [
                'qualification_id' => $this->qualification->id,
                'obtained_date' => now()->subMonth()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
                'certificate_number' => 'CERT-12345',
                'issuing_authority' => 'Test Authority',
                'status' => 'valid',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'employee_id',
                    'qualification_id',
                    'obtained_date',
                    'expiry_date',
                    'certificate_number',
                    'status',
                ],
            ]);

        expect($response->json('data.certificate_number'))->toBe('CERT-12345');
    });

    test('returns 409 when qualification already attached to employee', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_qualification.write');

        $this->employee->qualifications()->attach($this->qualification->id, [
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'obtained_date' => now()->toDateString(),
            'status' => 'valid',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/qualifications", [
                'qualification_id' => $this->qualification->id,
                'obtained_date' => now()->toDateString(),
                'status' => 'valid',
            ]);

        $response->assertStatus(409);
    });

    test('returns 422 when obtained_date is future', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_qualification.write');

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/qualifications", [
                'qualification_id' => $this->qualification->id,
                'obtained_date' => now()->addDay()->toDateString(),
                'status' => 'valid',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['obtained_date']);
    });

    test('returns 422 when expiry_date is before obtained_date', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_qualification.write');

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/qualifications", [
                'qualification_id' => $this->qualification->id,
                'obtained_date' => now()->toDateString(),
                'expiry_date' => now()->subDay()->toDateString(),
                'status' => 'valid',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['expiry_date']);
    });
});

describe('GET /v1/employee-qualifications/{employeeQualification}', function () {
    test('returns 401 when not authenticated', function (): void {
        $pivot = EmployeeQualification::factory()->create([
            'employee_id' => $this->employee->id,
            'qualification_id' => $this->qualification->id,
        ]);

        $response = $this->getJson("/v1/employee-qualifications/{$pivot->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee_qualification.read permission', function (): void {
        $pivot = EmployeeQualification::factory()->create([
            'employee_id' => $this->employee->id,
            'qualification_id' => $this->qualification->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employee-qualifications/{$pivot->id}");

        $response->assertStatus(403);
    });

    test('returns employee qualification details with relationships', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_qualification.read');

        $pivot = EmployeeQualification::factory()->create([
            'employee_id' => $this->employee->id,
            'qualification_id' => $this->qualification->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employee-qualifications/{$pivot->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'employee_id',
                    'qualification_id',
                    'employee',
                    'qualification',
                ],
            ]);
    });
});

describe('PATCH /v1/employee-qualifications/{employeeQualification}', function () {
    test('returns 401 when not authenticated', function (): void {
        $pivot = EmployeeQualification::factory()->create([
            'employee_id' => $this->employee->id,
            'qualification_id' => $this->qualification->id,
        ]);

        $response = $this->patchJson("/v1/employee-qualifications/{$pivot->id}", [
            'status' => 'expired',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee_qualification.write permission', function (): void {
        $pivot = EmployeeQualification::factory()->create([
            'employee_id' => $this->employee->id,
            'qualification_id' => $this->qualification->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employee-qualifications/{$pivot->id}", [
                'status' => 'expired',
            ]);

        $response->assertStatus(403);
    });

    test('updates employee qualification with valid data', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_qualification.write');

        $pivot = EmployeeQualification::factory()->create([
            'employee_id' => $this->employee->id,
            'qualification_id' => $this->qualification->id,
            'status' => 'valid',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employee-qualifications/{$pivot->id}", [
                'status' => 'expiring_soon',
                'notes' => 'Renewal required',
            ]);

        $response->assertStatus(200);
        expect($response->json('data.status'))->toBe('expiring_soon');
        expect($response->json('data.notes'))->toBe('Renewal required');
    });
});

describe('DELETE /v1/employee-qualifications/{employeeQualification}', function () {
    test('returns 401 when not authenticated', function (): void {
        $pivot = EmployeeQualification::factory()->create([
            'employee_id' => $this->employee->id,
            'qualification_id' => $this->qualification->id,
        ]);

        $response = $this->deleteJson("/v1/employee-qualifications/{$pivot->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee_qualification.write permission', function (): void {
        $pivot = EmployeeQualification::factory()->create([
            'employee_id' => $this->employee->id,
            'qualification_id' => $this->qualification->id,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/employee-qualifications/{$pivot->id}");

        $response->assertStatus(403);
    });

    test('deletes employee qualification with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_qualification.write');

        $pivot = EmployeeQualification::factory()->create([
            'employee_id' => $this->employee->id,
            'qualification_id' => $this->qualification->id,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/employee-qualifications/{$pivot->id}");

        $response->assertStatus(204);
        expect(EmployeeQualification::find($pivot->id))->toBeNull();
    });
});
