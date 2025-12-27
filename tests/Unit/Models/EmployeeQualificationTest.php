<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\Qualification;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @property \App\Models\TenantKey $tenant
 */
beforeEach(function () {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('employee qualification can be created', function () {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

    $employeeQual = EmployeeQualification::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
        'obtained_date' => now(),
        'status' => 'valid',
    ]);

    expect($employeeQual->id)->not->toBeNull()
        ->and($employeeQual->employee_id)->toBe($employee->id)
        ->and($employeeQual->qualification_id)->toBe($qualification->id);
});

test('employee qualification has employee relationship', function () {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

    $employeeQual = EmployeeQualification::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
        'obtained_date' => now(),
        'status' => 'valid',
    ]);

    expect($employeeQual->employee)->toBeInstanceOf(Employee::class)
        ->and($employeeQual->employee->id)->toBe($employee->id);
});

test('employee qualification has qualification relationship', function () {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

    $employeeQual = EmployeeQualification::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
        'obtained_date' => now(),
        'status' => 'valid',
    ]);

    expect($employeeQual->qualification)->toBeInstanceOf(Qualification::class)
        ->and($employeeQual->qualification->id)->toBe($qualification->id);
});

test('employee qualification casts dates correctly', function () {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

    $employeeQual = EmployeeQualification::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
        'obtained_date' => '2023-01-15',
        'expiry_date' => '2025-01-15',
        'status' => 'valid',
    ]);

    expect($employeeQual->obtained_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($employeeQual->expiry_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});
