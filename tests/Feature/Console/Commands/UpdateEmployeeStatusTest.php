<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->orgUnit = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Department',
        'code' => 'TEST',
        'type' => 'department',
        'is_active' => true,
    ]);
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('update employee status command activates employees whose contract starts today', function () {
    // Create pre_contract employee with contract starting today
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-100',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe@example.com',
        'date_of_birth' => '1990-01-01',
        'organizational_unit_id' => $this->orgUnit->id,
        'position' => 'Developer',
        'contract_start_date' => now()->startOfDay(),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    // Run command
    Artisan::call('employees:update-status');

    // Employee should be activated
    $employee->refresh();
    expect($employee->status)->toBe(Employee::STATUS_ACTIVE);
});

test('update employee status command deactivates employees whose contract ends today', function () {
    // Create active employee with termination today
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-101',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane.smith@example.com',
        'date_of_birth' => '1992-03-15',
        'organizational_unit_id' => $this->orgUnit->id,
        'position' => 'Manager',
        'contract_start_date' => now()->subMonths(6),
        'termination_date' => now()->startOfDay(),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    // Manually activate (bypass observer for test setup)
    $employee->status = Employee::STATUS_ACTIVE;
    $employee->saveQuietly();

    // Run command
    Artisan::call('employees:update-status');

    // Employee should be terminated
    $employee->refresh();
    expect($employee->status)->toBe(Employee::STATUS_TERMINATED);
});

test('update employee status command dry run does not change status', function () {
    // Create pre_contract employee with contract starting today
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-102',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Bob',
        'last_name' => 'Johnson',
        'email' => 'bob.johnson@example.com',
        'date_of_birth' => '1985-07-20',
        'organizational_unit_id' => $this->orgUnit->id,
        'position' => 'Developer',
        'contract_start_date' => now()->startOfDay(),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    // Run command with dry-run
    Artisan::call('employees:update-status', ['--dry-run' => true]);

    // Employee should still be pre_contract
    $employee->refresh();
    expect($employee->status)->toBe(Employee::STATUS_PRE_CONTRACT);
});

test('update employee status command handles errors gracefully', function () {
    // This test verifies the command completes successfully even with no employees to process
    $exitCode = Artisan::call('employees:update-status');

    expect($exitCode)->toBe(0);
});

test('update employee status command processes multiple employees', function () {
    // Create multiple employees to activate
    $employees = collect([
        Employee::factory()->create([
            'employee_number' => 'EMP-200',
            'first_name' => 'Alice',
            'last_name' => 'Brown',
            'email' => 'alice.brown@example.com',
            'date_of_birth' => '1995-05-10',
            'organizational_unit_id' => $this->orgUnit->id,
            'position' => 'Developer',
            'contract_start_date' => now()->startOfDay(),
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]),
        Employee::factory()->create([
            'employee_number' => 'EMP-201',
            'first_name' => 'Charlie',
            'last_name' => 'Davis',
            'email' => 'charlie.davis@example.com',
            'date_of_birth' => '1993-08-22',
            'organizational_unit_id' => $this->orgUnit->id,
            'position' => 'Designer',
            'contract_start_date' => now()->startOfDay(),
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]),
    ]);

    // Run command
    Artisan::call('employees:update-status');

    // All employees should be activated
    $employees->each(function ($employee) {
        $employee->refresh();
        expect($employee->status)->toBe(Employee::STATUS_ACTIVE);
    });
});
