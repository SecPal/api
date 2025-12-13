<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Mail\AccountDeactivatedMail;
use App\Mail\OnboardingInvitationMail;
use App\Mail\WelcomeActiveMail;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Setup TenantKey for encrypted fields
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Create test roles
    Role::create(['name' => 'Employee', 'guard_name' => 'sanctum']);

    // Create a test organizational unit
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

test('employee observer creates user account when status changes to pre_contract', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'email' => 'john.doe@example.com',
        'contract_start_date' => now()->addDays(7),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    // User should be created
    expect($employee->user)->not->toBeNull();
    expect($employee->user->email)->toBe('john.doe@example.com');

    // Onboarding invitation email should be queued
    Mail::assertQueued(OnboardingInvitationMail::class, function ($mail) use ($employee) {
        return $mail->employee->id === $employee->id;
    });
});

test('employee observer activates user account when status changes to active', function () {
    Mail::fake();

    // Create employee with pre_contract status
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-002',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane.smith@example.com',
        'date_of_birth' => '1992-03-15',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now(),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $user = $employee->user;
    expect($user)->not->toBeNull();

    // Transition to active
    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    // User should have Employee role
    $user->refresh();
    expect($user->hasRole('Employee'))->toBeTrue();

    // Welcome email should be queued
    Mail::assertQueued(WelcomeActiveMail::class, function ($mail) use ($employee) {
        return $mail->employee->id === $employee->id;
    });
});

test('employee observer deactivates user account when status changes to terminated', function () {
    Mail::fake();

    // Create active employee
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-003',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Bob',
        'last_name' => 'Johnson',
        'email' => 'bob.johnson@example.com',
        'date_of_birth' => '1985-07-20',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->subMonths(6),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    // Activate
    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    $user = $employee->user;
    expect($user)->not->toBeNull();
    expect($user->hasRole('Employee'))->toBeTrue();

    // Terminate
    $employee->termination_date = now();
    $employee->status = Employee::STATUS_TERMINATED;
    $employee->save();

    // User should have no roles
    $user->refresh();
    expect($user->roles)->toBeEmpty();

    // Deactivation email should be queued
    Mail::assertQueued(AccountDeactivatedMail::class, function ($mail) use ($employee) {
        return $mail->employee->id === $employee->id;
    });
});

test('employee observer does not create duplicate user account', function () {
    Mail::fake();

    // Create employee with existing user
    $existingUser = User::create([
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'password' => bcrypt('password'),
    ]);

    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-004',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Existing',
        'last_name' => 'User',
        'email' => 'existing@example.com',
        'date_of_birth' => '1988-11-30',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->addDays(14),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    // Should reuse existing user
    $employee->refresh();
    expect($employee->user)->not->toBeNull();
    expect($employee->user->id)->toBe($existingUser->id);

    // Only one user should exist with this email
    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
});

test('employee observer handles transition from pre_contract to terminated', function () {
    Mail::fake();

    // Create pre_contract employee
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-005',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Alice',
        'last_name' => 'Brown',
        'email' => 'alice.brown@example.com',
        'date_of_birth' => '1995-05-10',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->addDays(30),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $user = $employee->user;
    expect($user)->not->toBeNull();

    // Terminate before contract starts
    $employee->status = Employee::STATUS_TERMINATED;
    $employee->termination_date = now();
    $employee->save();

    // User should have no roles
    $user->refresh();
    expect($user->roles)->toBeEmpty();

    // Deactivation email should be queued
    Mail::assertQueued(AccountDeactivatedMail::class);
});

test('employee observer handles transition to on_leave status', function () {
    Mail::fake();

    // Create active employee
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->subMonths(3),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    // Activate
    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    // Put on leave
    $employee->status = Employee::STATUS_ON_LEAVE;
    $employee->save();

    // Should complete without errors
    expect($employee->status)->toBe(Employee::STATUS_ON_LEAVE);
});

test('employee observer handles transition from on_leave to active', function () {
    Mail::fake();

    // Create active employee then put on leave
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->subMonths(3),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    $employee->status = Employee::STATUS_ON_LEAVE;
    $employee->save();

    // Return from leave
    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    // Should complete without errors
    expect($employee->status)->toBe(Employee::STATUS_ACTIVE);
});

test('employee observer handles transition from on_leave to terminated', function () {
    Mail::fake();

    // Create active employee, put on leave, then terminate
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->subMonths(3),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    $employee->status = Employee::STATUS_ON_LEAVE;
    $employee->save();

    $user = $employee->user;
    expect($user)->not->toBeNull();

    // Terminate while on leave
    $employee->status = Employee::STATUS_TERMINATED;
    $employee->termination_date = now();
    $employee->save();

    // User should have no roles
    $user->refresh();
    expect($user->roles)->toBeEmpty();

    // Deactivation email should be queued
    Mail::assertQueued(AccountDeactivatedMail::class);
});

test('employee observer updates blind indexes when encrypted fields change', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'Original',
        'last_name' => 'Name',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $originalFirstNameIdx = $employee->first_name_idx;
    $originalLastNameIdx = $employee->last_name_idx;

    // Update encrypted fields
    $employee->first_name = 'Updated';
    $employee->last_name = 'Changed';
    $employee->save();

    // Indexes should be updated
    $employee->refresh();
    expect($employee->first_name_idx)->not->toBe($originalFirstNameIdx);
    expect($employee->last_name_idx)->not->toBe($originalLastNameIdx);
});

test('employee observer does not trigger status transition when status unchanged', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_ACTIVE,
    ]);

    // Update non-status field
    $employee->employee_number = 'NEW-001';
    $employee->save();

    // No welcome email should be sent
    Mail::assertNothingQueued();
});

test('employee observer creates user immediately when status=pre_contract during Employee::create() - Issue #345', function () {
    Mail::fake();

    $tenant = TenantKey::first();
    $orgUnit = OrganizationalUnit::first();

    // This test reproduces the exact scenario from Issue #345
    // Create employee with status=pre_contract using Employee::create()
    // (not factory) to ensure observer fires correctly
    $employee = Employee::create([
        'tenant_id' => $tenant->id,
        'organizational_unit_id' => $orgUnit->id,
        'employee_number' => 'EMP-TEST-'.time(),
        'first_name' => 'Issue',
        'last_name' => 'Test',
        'email' => 'issue.test.'.time().'@example.com',
        'date_of_birth' => '1990-01-01',
        'status' => Employee::STATUS_PRE_CONTRACT,
        'contract_type' => 'full_time',
        'contract_start_date' => now()->addDays(7)->toDateString(),
        'weekly_hours' => 40.0,
        'hourly_rate' => 15.50,
    ]);

    // Refresh to get latest state
    $employee->refresh();

    // User account MUST be created automatically by Observer
    expect($employee->user_id)->not->toBeNull('User account was not created by Observer');
    expect($employee->user)->not->toBeNull('User relationship is null');
    expect($employee->user->email)->toBe($employee->email);
    expect($employee->user_account_active)->toBeTrue();

    // Onboarding invitation email should be queued
    Mail::assertQueued(OnboardingInvitationMail::class);
});
