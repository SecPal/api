<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property OrganizationalUnit $orgUnit
 */
beforeEach(function () {
    incrementTestKekCounter();
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

    Role::create(['name' => 'Employee', 'guard_name' => 'sanctum']);
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
        'contract_start_date' => now()->startOfDay(),
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    // Run command
    Artisan::call('employees:update-status');

    // Employee should be activated
    $employee->refresh();
    expect($employee->status)->toBe(Employee::STATUS_ACTIVE);
    expect($employee->user?->hasRole('Employee'))->toBeTrue();
});

test('update employee status command ignores unrelated unassignable organizational units', function (): void {
    $this->orgUnit->update(['is_assignable' => false]);

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'contract_start_date' => now()->startOfDay(),
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    Artisan::call('employees:update-status');

    $employee->refresh();

    expect($employee->status)->toBe(Employee::STATUS_ACTIVE);
    expect($employee->user?->hasRole('Employee'))->toBeTrue();
});

test('update employee status command ignores unrelated deleted organizational units', function (): void {
    $this->orgUnit->delete();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'contract_start_date' => now()->startOfDay(),
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    Artisan::call('employees:update-status');

    $employee->refresh();

    expect($employee->status)->toBe(Employee::STATUS_ACTIVE);
    expect($employee->user?->hasRole('Employee'))->toBeTrue();
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
    expect($employee->user?->roles()->count())->toBe(0);
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
        'contract_start_date' => now()->startOfDay(),
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
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
            'tenant_id' => $this->tenant->id,
            'contract_start_date' => now()->startOfDay(),
            'onboarding_completed' => true,
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]),
        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'contract_start_date' => now()->startOfDay(),
            'onboarding_completed' => true,
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
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

test('update employee status command skips activation when onboarding is incomplete', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'contract_start_date' => now()->startOfDay(),
        'onboarding_completed' => false,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    Artisan::call('employees:update-status');

    $employee->refresh();
    expect($employee->status)->toBe(Employee::STATUS_PRE_CONTRACT);
    expect(DB::table('model_has_roles')->where('model_id', $employee->user_id)->count())->toBe(0);
});

test('update employee status command promotes contract_confirmed employees to ready_for_activation and activates them', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'contract_start_date' => now()->startOfDay(),
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    Artisan::call('employees:update-status');

    $employee->refresh();

    expect($employee->status)->toBe(Employee::STATUS_ACTIVE);
    expect(Artisan::output())->not->toContain('Failed to activate employee');
});

test('update employee status command skips employees whose workflow is in_progress (not confirmed)', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'contract_start_date' => now()->startOfDay(),
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_IN_PROGRESS,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    Artisan::call('employees:update-status');

    $employee->refresh();

    expect($employee->status)->toBe(Employee::STATUS_PRE_CONTRACT);
    expect(Artisan::output())->not->toContain('Failed to activate employee');
});

test('update employee status command terminates on-leave employees whose contract ends today', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'contract_start_date' => now()->subMonths(3),
        'termination_date' => now()->startOfDay(),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $employee->status = Employee::STATUS_ON_LEAVE;
    $employee->saveQuietly();

    Artisan::call('employees:update-status');

    $employee->refresh();
    expect($employee->status)->toBe(Employee::STATUS_TERMINATED);
});
