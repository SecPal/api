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

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    Permission::create(['name' => 'employee_qualification.read', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'employee_qualification.write', 'guard_name' => 'sanctum']);

    $organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $organizationalUnit->id,
    ]);

    $this->qualification = Qualification::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
});

afterEach(function (): void {
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
            ->assertJsonValidationErrors(['qualification_id', 'obtained_date', 'status']);
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
                'id',
                'employee_id',
                'qualification_id',
                'obtained_date',
                'expiry_date',
                'certificate_number',
                'status',
            ]);

        expect($response->json('certificate_number'))->toBe('CERT-12345');
    });

    test('returns 409 when qualification already attached to employee', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_qualification.write');

        $this->employee->qualifications()->attach($this->qualification->id, [
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
                'id',
                'employee_id',
                'qualification_id',
                'employee',
                'qualification',
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
        expect($response->json('status'))->toBe('expiring_soon');
        expect($response->json('notes'))->toBe('Renewal required');
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
