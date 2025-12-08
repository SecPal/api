<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Mail\AccountDeactivatedMail;
use App\Mail\ContractEndingSoonMail;
use App\Mail\OnboardingInvitationMail;
use App\Mail\QualificationExpiringMail;
use App\Mail\WelcomeActiveMail;
use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\OrganizationalUnit;
use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

test('onboarding invitation mail has correct content', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe.onboarding@example.com',
        'contract_start_date' => now()->addDays(14),
        'status' => Employee::STATUS_ACTIVE, // Avoid observer creating user
    ]);

    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john.doe.onboarding@example.com',
        'password' => bcrypt('password'),
    ]);

    $mail = new OnboardingInvitationMail($employee, $user);

    $content = $mail->content();
    expect($content->markdown)->toBe('emails.employees.onboarding-invitation');
});

test('onboarding invitation mail generates password reset token', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'email' => 'test.reset@example.com',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $user = User::create([
        'name' => 'Test User',
        'email' => 'test.reset@example.com',
        'password' => bcrypt('password'),
    ]);

    $mail = new OnboardingInvitationMail($employee, $user);

    // Should have employee and user
    expect($mail->employee->id)->toBe($employee->id);
    expect($mail->user->id)->toBe($user->id);
});

test('welcome active mail has correct content', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane.smith.welcome@example.com',
        'status' => Employee::STATUS_ACTIVE,
        'employee_number' => 'EMP-WELCOME-001',
    ]);

    $mail = new WelcomeActiveMail($employee);

    $content = $mail->content();
    expect($content->markdown)->toBe('emails.employees.welcome-active');
});

test('welcome active mail contains employee data', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_ACTIVE,
        'employee_number' => 'TEST-002',
    ]);

    $mail = new WelcomeActiveMail($employee);

    expect($mail->employee->id)->toBe($employee->id);
    expect($mail->employee->employee_number)->toBe('TEST-002');
});

test('account deactivated mail has correct content', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'Bob',
        'last_name' => 'Johnson',
        'email' => 'bob.johnson.deactivated@example.com',
        'status' => Employee::STATUS_TERMINATED,
        'termination_date' => now(),
    ]);

    $mail = new AccountDeactivatedMail($employee);

    $content = $mail->content();
    expect($content->markdown)->toBe('emails.employees.account-deactivated');
});

test('account deactivated mail contains termination date', function () {
    $terminationDate = now()->subDays(1);
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_TERMINATED,
        'termination_date' => $terminationDate,
    ]);

    $mail = new AccountDeactivatedMail($employee);

    expect($mail->employee->termination_date->format('Y-m-d'))->toBe($terminationDate->format('Y-m-d'));
});

test('contract ending soon mail has correct content', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'Alice',
        'last_name' => 'Brown',
        'email' => 'alice.brown.ending@example.com',
        'status' => Employee::STATUS_ACTIVE,
        'termination_date' => now()->addDays(30),
    ]);

    $mail = new ContractEndingSoonMail($employee);

    $content = $mail->content();
    expect($content->markdown)->toBe('emails.employees.contract-ending-soon');
});

test('contract ending soon mail contains future termination date', function () {
    $futureDate = now()->addDays(45);
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'termination_date' => $futureDate,
    ]);

    $mail = new ContractEndingSoonMail($employee);

    expect($mail->employee->termination_date->format('Y-m-d'))->toBe($futureDate->format('Y-m-d'));
});

test('qualification expiring mail has correct content', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'Charlie',
        'last_name' => 'Wilson',
        'email' => 'charlie.wilson.qual@example.com',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $qualification = Qualification::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'First Aid Certificate',
        'code' => 'FIRST-AID',
        'is_active' => true,
    ]);

    $empQual = EmployeeQualification::create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
        'obtained_date' => now()->subYears(2),
        'expiry_date' => now()->addDays(30),
        'status' => EmployeeQualification::STATUS_EXPIRING,
    ]);

    $mail = new QualificationExpiringMail($empQual);

    $content = $mail->content();
    expect($content->markdown)->toBe('emails.employees.qualification-expiring');
});

test('qualification expiring mail contains qualification data', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
    ]);

    $qualification = Qualification::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Safety Training',
        'code' => 'SAFETY-001',
        'is_active' => true,
    ]);

    $expiryDate = now()->addDays(25);
    $empQual = EmployeeQualification::create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
        'obtained_date' => now()->subYear(),
        'expiry_date' => $expiryDate,
        'status' => EmployeeQualification::STATUS_EXPIRING,
        'certificate_number' => 'CERT-12345',
        'issuing_authority' => 'Test Authority',
    ]);

    $mail = new QualificationExpiringMail($empQual);

    expect($mail->qualification->qualification->name)->toBe('Safety Training');
    expect($mail->qualification->certificate_number)->toBe('CERT-12345');
    expect($mail->qualification->issuing_authority)->toBe('Test Authority');
});

