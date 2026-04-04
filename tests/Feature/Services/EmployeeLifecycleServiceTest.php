<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Mail\AccountDeactivatedMail;
use App\Mail\WelcomeActiveMail;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\EmployeeLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property OrganizationalUnit $orgUnit
 * @property EmployeeLifecycleService $service
 */
beforeEach(function () {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    Role::create(['name' => 'Employee', 'guard_name' => 'sanctum']);

    $this->orgUnit = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Department',
        'code' => 'TEST',
        'type' => 'department',
        'is_active' => true,
    ]);

    $this->service = app(EmployeeLifecycleService::class);
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('employee lifecycle service activates employee atomically', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => true,
        'contract_start_date' => now()->subDay(),
    ]);

    $activatedEmployee = $this->service->activate($employee);

    expect($activatedEmployee->status)->toBe(Employee::STATUS_ACTIVE);
    expect($activatedEmployee->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_ACTIVE);
    expect($activatedEmployee->user_account_active)->toBeTrue();
    expect($activatedEmployee->user_account_activated_at)->not->toBeNull();
    expect($activatedEmployee->user?->hasRole('Employee'))->toBeTrue();

    Mail::assertQueued(WelcomeActiveMail::class, function ($mail) use ($activatedEmployee) {
        return $mail->employee->id === $activatedEmployee->id;
    });
});

test('employee lifecycle service rolls activation back when employee role is missing', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => true,
        'contract_start_date' => now()->subDay(),
    ]);

    Role::query()->delete();

    expect(fn () => $this->service->activate($employee))
        ->toThrow(RuntimeException::class, 'Role "Employee" not found.');

    $employee->refresh();

    expect($employee->status)->toBe(Employee::STATUS_PRE_CONTRACT);
    expect($employee->user?->hasRole('Employee'))->toBeFalse();

    Mail::assertNothingQueued();
});

test('employee lifecycle service rejects activation when employee has no linked user account', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => true,
        'contract_start_date' => now()->subDay(),
    ]);

    $employee->updateQuietly([
        'user_id' => null,
        'user_account_active' => false,
        'user_account_activated_at' => null,
    ]);

    expect(fn () => $this->service->activate($employee))
        ->toThrow(ValidationException::class);

    $employee->refresh();

    expect($employee->status)->toBe(Employee::STATUS_PRE_CONTRACT);
    Mail::assertNothingQueued();
});

test('employee lifecycle service terminates employee and revokes runtime access atomically', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => true,
        'contract_start_date' => now()->subMonths(2),
        'termination_date' => now()->toDateString(),
    ]);

    $activatedEmployee = $this->service->activate($employee);
    $user = $activatedEmployee->user;

    expect($user)->toBeInstanceOf(User::class);

    $user->createToken('integration-test-token');

    DB::table('sessions')->insert([
        'id' => 'employee-lifecycle-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => base64_encode('test'),
        'last_activity' => now()->timestamp,
    ]);

    $terminatedEmployee = $this->service->terminate($activatedEmployee);

    $user->refresh();

    expect($terminatedEmployee->status)->toBe(Employee::STATUS_TERMINATED);
    expect($terminatedEmployee->user_account_active)->toBeFalse();
    expect($terminatedEmployee->user_account_deactivated_at)->not->toBeNull();
    expect($user->roles()->count())->toBe(0);
    expect($user->tokens()->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    Mail::assertQueued(AccountDeactivatedMail::class, function ($mail) use ($terminatedEmployee) {
        return $mail->employee->id === $terminatedEmployee->id;
    });
});
