<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

/**
 * @property TenantKey $tenant
 * @property OrganizationalUnit $orgUnit
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // Setup TenantKey for encrypted fields
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Create test roles
    Role::create(['name' => 'Employee', 'guard_name' => 'sanctum']);
    Role::create(['name' => 'Employee Read Only', 'guard_name' => 'sanctum']);

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

test('employee observer creates user account when status changes to pre_contract without automatically sending invitation mail', function () {
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

    Mail::assertNothingQueued();
});

test('employee observer reuses existing user account in same tenant', function () {
    Mail::fake();

    $existingUser = User::factory()->create([
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'password' => bcrypt('password'),
        'tenant_id' => $this->tenant->id,
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

    $employee->refresh();

    expect($employee->user)->not->toBeNull();
    expect($employee->user->id)->toBe($existingUser->id);
    expect(User::where('email', 'existing@example.com')->count())->toBe(1);

    Mail::assertNothingQueued();
});

test('employee observer does not reuse user account from another tenant', function () {
    Mail::fake();

    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $otherOrgUnit = OrganizationalUnit::create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Other Department',
        'code' => 'OTHER',
        'type' => 'department',
        'is_active' => true,
    ]);

    $otherTenantUser = User::factory()->create([
        'name' => 'Other Tenant User',
        'email' => 'conflict@example.com',
        'tenant_id' => $otherTenant->id,
    ]);

    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-004A',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Conflict',
        'last_name' => 'User',
        'email' => 'conflict@example.com',
        'date_of_birth' => '1988-11-30',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->addDays(14),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $employee->refresh();

    expect($employee->user)->toBeNull();
    expect($employee->user_account_active)->toBeFalse();
    expect($employee->user_account_activated_at)->toBeNull();
    expect(User::where('email', 'conflict@example.com')->count())->toBe(1);
    expect(User::whereKey($otherTenantUser->id)->value('tenant_id'))->toBe($otherTenant->id);
    expect($otherOrgUnit->tenant_id)->toBe($otherTenant->id);

    Mail::assertNothingQueued();
});

test('employee observer does not reuse user account already linked to another employee', function () {
    Mail::fake();

    $existingUser = User::factory()->create([
        'name' => 'Existing User',
        'email' => 'linked@example.com',
        'tenant_id' => $this->tenant->id,
    ]);

    $firstEmployee = Employee::factory()->create([
        'employee_number' => 'EMP-004B',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'First',
        'last_name' => 'Employee',
        'email' => 'linked@example.com',
        'date_of_birth' => '1988-11-30',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->addDays(7),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    expect($firstEmployee->fresh()?->user_id)->toBe($existingUser->id);

    $existingUser->forceFill([
        'email' => 'duplicate@example.com',
    ])->save();

    Mail::fake();

    $secondEmployee = Employee::factory()->create([
        'employee_number' => 'EMP-004C',
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Second',
        'last_name' => 'Employee',
        'email' => 'duplicate@example.com',
        'date_of_birth' => '1990-12-01',
        'organizational_unit_id' => $this->orgUnit->id,
        'contract_start_date' => now()->addDays(14),
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $secondEmployee->refresh();

    expect($secondEmployee->user)->toBeNull();
    expect($secondEmployee->user_account_active)->toBeFalse();
    expect($secondEmployee->user_account_activated_at)->toBeNull();
    expect(User::where('email', 'duplicate@example.com')->count())->toBe(1);
    expect($existingUser->fresh()?->employee?->id)->toBe($firstEmployee->id);

    Mail::assertNothingQueued();
});

test('employee observer does not activate user account when status changes to active directly', function () {
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

    // Transition to active without explicit lifecycle service
    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    // Direct status writes should no longer provision runtime access implicitly
    $user->refresh();
    expect($user->hasRole('Employee'))->toBeFalse();

    Mail::assertNothingQueued();
});

test('employee observer does not deactivate user account when status changes to terminated directly', function () {
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

    $user = $employee->user;
    expect($user)->not->toBeNull();

    DB::table('model_has_roles')->insert([
        'role_id' => Role::where('name', 'Employee')->value('id'),
        'model_type' => User::class,
        'model_id' => $user->id,
        'tenant_id' => $employee->tenant_id,
        'valid_from' => now()->subMonth(),
        'valid_until' => null,
        'auto_revoke' => true,
        'assigned_by' => null,
        'reason' => 'Test setup',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $employee->status = Employee::STATUS_ACTIVE;
    $employee->saveQuietly();

    // Terminate without explicit lifecycle service
    $employee->termination_date = now();
    $employee->status = Employee::STATUS_TERMINATED;
    $employee->save();

    // Existing runtime access should remain unchanged on a direct status write
    $user->refresh();
    expect($user->hasRole('Employee'))->toBeTrue();

    Mail::assertNothingQueued();
});

test('employee observer does not reduce runtime access when status changes to on_leave directly', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $user = $employee->user;
    expect($user)->not->toBeNull();

    DB::table('model_has_roles')->insert([
        'role_id' => Role::where('name', 'Employee')->value('id'),
        'model_type' => User::class,
        'model_id' => $user->id,
        'tenant_id' => $employee->tenant_id,
        'valid_from' => now()->subMonth(),
        'valid_until' => null,
        'auto_revoke' => true,
        'assigned_by' => null,
        'reason' => 'Test setup',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $employee->status = Employee::STATUS_ACTIVE;
    $employee->saveQuietly();

    $employee->status = Employee::STATUS_ON_LEAVE;
    $employee->save();

    $user->refresh();

    expect($user->hasRole('Employee'))->toBeTrue();
    expect($user->hasRole('Employee Read Only'))->toBeFalse();
    expect($employee->fresh()?->runtime_access_snapshot)->toBeNull();

    Mail::assertNothingQueued();
});

test('employee observer does not restore runtime access when status changes from on_leave directly', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $user = $employee->user;
    expect($user)->not->toBeNull();

    DB::table('model_has_roles')->insert([
        'role_id' => Role::where('name', 'Employee Read Only')->value('id'),
        'model_type' => User::class,
        'model_id' => $user->id,
        'tenant_id' => $employee->tenant_id,
        'valid_from' => now()->subDay(),
        'valid_until' => null,
        'auto_revoke' => true,
        'assigned_by' => null,
        'reason' => 'Test setup',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $employee->forceFill([
        'status' => Employee::STATUS_ON_LEAVE,
        'runtime_access_snapshot' => [
            'roles' => [[
                'role_id' => Role::where('name', 'Employee')->value('id'),
                'valid_from' => now()->subMonth()->toDateTimeString(),
                'valid_until' => null,
                'auto_revoke' => true,
                'assigned_by' => null,
                'reason' => 'Snapshot',
                'created_at' => now()->subMonth()->toDateTimeString(),
                'updated_at' => now()->subMonth()->toDateTimeString(),
            ]],
            'permissions' => [],
        ],
    ])->saveQuietly();

    $employee->status = Employee::STATUS_ACTIVE;
    $employee->save();

    $user->refresh();

    expect($user->hasRole('Employee'))->toBeFalse();
    expect($user->hasRole('Employee Read Only'))->toBeTrue();
    expect($employee->fresh()?->runtime_access_snapshot)->not->toBeNull();

    Mail::assertNothingQueued();
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

test('employee observer creates user immediately when status=pre_contract during Employee::create() without auto-sending an invitation - Issue #345', function () {
    Mail::fake();

    // This test reproduces the exact scenario from Issue #345
    // Verifies observer works with direct model creation (not just via factory) for comprehensive coverage
    $uniqueId = Illuminate\Support\Str::random(8);
    $employee = Employee::create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'employee_number' => 'EMP-TEST-'.$uniqueId,
        'first_name' => 'Issue',
        'last_name' => 'Test',
        'email' => 'issue.test.'.$uniqueId.'@example.com',
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

    expect($employee->onboarding_invitation_status)->toBe(Employee::INVITATION_STATUS_NOT_REQUESTED);

    Mail::assertNothingQueued();
});
