<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Mail\ContractEndingSoonMail;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property OrganizationalUnit $orgUnit
 */
beforeEach(function () {
    Mail::fake();

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

test('send contract ending notifications command sends emails 7 days before termination', function () {
    // Create active employee with contract ending in exactly 7 days
    // Start with pre_contract to let observer create user account
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-400',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe@example.com',
        'date_of_birth' => '1990-01-01',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->subMonths(6),
        'termination_date' => now()->addDays(7),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    // Activate employee
    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    // Clear any emails from observer (onboarding, welcome)
    Mail::fake();

    // Run command
    Artisan::call('employees:send-contract-ending-notifications');

    // Only ContractEndingSoonMail should be queued
    Mail::assertQueued(ContractEndingSoonMail::class, function ($mail) use ($employee) {
        return $mail->employee->id === $employee->id;
    });
});

test('send contract ending notifications command skips employees without termination date', function () {
    // Create active employee without termination date
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->subMonths(6),
        'termination_date' => null,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    // Clear any emails from observer
    Mail::fake();

    // Run command
    Artisan::call('employees:send-contract-ending-notifications');

    // No email should be queued
    Mail::assertNothingQueued();
});

test('send contract ending notifications command dry run does not send emails', function () {
    // Create active employee with contract ending in exactly 7 days
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-401',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane.smith@example.com',
        'date_of_birth' => '1992-03-15',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->subMonths(6),
        'termination_date' => now()->addDays(7),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    // Clear any emails from observer
    Mail::fake();

    // Run command with dry-run
    Artisan::call('employees:send-contract-ending-notifications', ['--dry-run' => true]);

    // No email should be queued
    Mail::assertNothingQueued();
});

test('send contract ending notifications command handles multiple employees', function () {
    // Create multiple employees with contracts ending in 7 days
    $employees = collect([
        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->orgUnit->id,
            'contract_start_date' => now()->subMonths(6),
            'termination_date' => now()->addDays(7),
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]),
        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->orgUnit->id,
            'contract_start_date' => now()->subMonths(6),
            'termination_date' => now()->addDays(7),
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]),
    ]);

    // Activate employees
    $employees->each(function ($employee) {
        $employee->status = Employee::STATUS_ACTIVE;
        $employee->save();
    });

    // Clear any emails from observer
    Mail::fake();

    // Run command
    Artisan::call('employees:send-contract-ending-notifications');

    // Emails should be queued
    Mail::assertQueued(ContractEndingSoonMail::class, 2);
});
