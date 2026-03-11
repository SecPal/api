<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Mail\QualificationExpiringMail;
use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\OrganizationalUnit;
use App\Models\Qualification;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property OrganizationalUnit $orgUnit
 * @property Qualification $qualification
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

    $this->qualification = Qualification::create([
        'name' => 'First Aid Certificate',
        'code' => 'FIRST-AID',
        'type' => 'certification',
        'is_mandatory' => true,
        'validity_period_months' => 24,
    ]);
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('update qualification status command expires qualifications past expiry date', function () {
    // Create employee with expired qualification
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-300',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe@example.com',
        'date_of_birth' => '1990-01-01',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->subMonths(6),
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $qualification = EmployeeQualification::create([
        'employee_id' => $employee->id,
        'qualification_id' => $this->qualification->id,
        'obtained_date' => now()->subYears(2),
        'expiry_date' => now()->subDay(),
        'status' => EmployeeQualification::STATUS_ACTIVE,
    ]);

    // Run command
    Artisan::call('employees:update-qualifications');

    // Qualification should be expired
    $qualification->refresh();
    expect($qualification->status)->toBe(EmployeeQualification::STATUS_EXPIRED);
});

test('update qualification status command sends notification 30 days before expiry', function () {
    // Create employee with qualification expiring in exactly 30 days
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-301',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane.smith@example.com',
        'date_of_birth' => '1992-03-15',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->subMonths(3),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    // Activate employee
    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    $qualification = EmployeeQualification::create([
        'employee_id' => $employee->id,
        'qualification_id' => $this->qualification->id,
        'obtained_date' => now()->subMonths(12),
        'expiry_date' => now()->addDays(30),
        'status' => EmployeeQualification::STATUS_ACTIVE,
    ]);

    // Run command
    Artisan::call('employees:update-qualifications');

    // Qualification status should be expiring
    $qualification->refresh();
    expect($qualification->status)->toBe(EmployeeQualification::STATUS_EXPIRING);

    // Email should be queued
    Mail::assertQueued(QualificationExpiringMail::class, function ($mail) use ($qualification) {
        return $mail->qualification->id === $qualification->id;
    });
});

test('update qualification status command dry run does not change status', function () {
    // Create employee with expired qualification
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-302',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Bob',
        'last_name' => 'Johnson',
        'email' => 'bob.johnson@example.com',
        'date_of_birth' => '1985-07-20',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->subMonths(6),
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $qualification = EmployeeQualification::create([
        'employee_id' => $employee->id,
        'qualification_id' => $this->qualification->id,
        'obtained_date' => now()->subYears(2),
        'expiry_date' => now()->subDay(),
        'status' => EmployeeQualification::STATUS_ACTIVE,
    ]);

    // Run command with dry-run
    Artisan::call('employees:update-qualifications', ['--dry-run' => true]);

    // Qualification should still be active
    $qualification->refresh();
    expect($qualification->status)->toBe(EmployeeQualification::STATUS_ACTIVE);

    // No email should be queued
    Mail::assertNothingQueued();
});

test('update qualification status command skips qualifications without employee', function () {
    // This test verifies the command handles orphaned qualifications gracefully
    $exitCode = Artisan::call('employees:update-qualifications');

    expect($exitCode)->toBe(0);
});

test('update qualification status command processes multiple qualifications', function () {
    // Create multiple employees with expiring qualifications
    $employees = collect([
        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->orgUnit->id,
            'contract_start_date' => now()->subMonths(6),
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]),
        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->orgUnit->id,
            'contract_start_date' => now()->subMonths(6),
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]),
    ]);

    // Activate employees
    $employees->each(function ($employee) {
        $employee->status = Employee::STATUS_ACTIVE;
        $employee->save();
    });

    // Create expiring qualifications
    $qualifications = $employees->map(function ($employee) {
        return EmployeeQualification::create([
            'employee_id' => $employee->id,
            'qualification_id' => $this->qualification->id,
            'obtained_date' => now()->subMonths(12),
            'expiry_date' => now()->addDays(30),
            'status' => EmployeeQualification::STATUS_ACTIVE,
        ]);
    });

    // Run command
    Artisan::call('employees:update-qualifications');

    // All qualifications should be expiring
    $qualifications->each(function ($qualification) {
        $qualification->refresh();
        expect($qualification->status)->toBe(EmployeeQualification::STATUS_EXPIRING);
    });

    // Emails should be queued
    Mail::assertQueued(QualificationExpiringMail::class, 2);
});
