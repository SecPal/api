<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Mail\AccountDeactivatedMail;
use App\Mail\WelcomeActiveMail;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\OnboardingSubmissionFile;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\RoleAssignmentLog;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\EmployeeLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function seedEmployeeLifecycleRbac(): void
{
    foreach (['employee.read', 'employee.update', 'employee.delete'] as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'sanctum']);
    }

    $employeeRole = Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'sanctum']);
    $employeeRole->syncPermissions(['employee.read', 'employee.update']);

    $readOnlyRole = Role::firstOrCreate(['name' => 'Employee Read Only', 'guard_name' => 'sanctum']);
    $readOnlyRole->syncPermissions(['employee.read']);
}

/**
 * @property TenantKey $tenant
 * @property OrganizationalUnit $orgUnit
 * @property EmployeeLifecycleService $service
 */
beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    seedEmployeeLifecycleRbac();

    $this->orgUnit = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Department',
        'code' => 'TEST',
        'type' => 'department',
        'is_active' => true,
    ]);

    $this->service = app(EmployeeLifecycleService::class);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('employee lifecycle RBAC bootstrap tolerates pre-seeded permissions and roles', function () {
    seedEmployeeLifecycleRbac();

    expect(Permission::query()->whereIn('name', [
        'employee.read',
        'employee.update',
        'employee.delete',
    ])->pluck('name')->all())->toEqualCanonicalizing([
        'employee.read',
        'employee.update',
        'employee.delete',
    ]);

    $employeeRole = Role::findByName('Employee', 'sanctum');
    $readOnlyRole = Role::findByName('Employee Read Only', 'sanctum');

    expect($employeeRole->permissions->pluck('name')->all())->toEqualCanonicalizing([
        'employee.read',
        'employee.update',
    ]);
    expect($readOnlyRole->permissions->pluck('name')->all())->toEqualCanonicalizing([
        'employee.read',
    ]);
});

test('employee lifecycle service activates employee atomically', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
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
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
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
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
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

test('employee lifecycle service rejects activation when onboarding workflow is not ready', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED,
        'contract_start_date' => now()->subDay(),
    ]);

    expect(fn () => $this->service->activate($employee))
        ->toThrow(ValidationException::class);

    $employee->refresh();

    expect($employee->status)->toBe(Employee::STATUS_PRE_CONTRACT);
    expect($employee->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED);
    Mail::assertNothingQueued();
});

test('employee lifecycle service terminates employee and revokes runtime access atomically', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
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

test('employee lifecycle service places active employee on leave with read-only runtime access', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'contract_start_date' => now()->subWeek(),
    ]);

    $activeEmployee = $this->service->activate($employee);
    $user = $activeEmployee->user;

    expect($user)->toBeInstanceOf(User::class);

    givePermissionWithTenant($user, $this->tenant->id, 'employee.delete');

    $onLeaveEmployee = $this->service->placeOnLeave($activeEmployee);

    $user->refresh();

    expect($onLeaveEmployee->status)->toBe(Employee::STATUS_ON_LEAVE);
    expect($onLeaveEmployee->runtime_access_snapshot)->not->toBeNull();
    expect($user->hasRole('Employee'))->toBeFalse();
    expect($user->hasRole('Employee Read Only'))->toBeTrue();
    expect($user->can('employee.read'))->toBeTrue();
    expect($user->can('employee.update'))->toBeFalse();
    expect($user->can('employee.delete'))->toBeFalse();
});

test('employee lifecycle service restores the prior runtime access model when returning from leave', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'contract_start_date' => now()->subWeek(),
    ]);

    $activeEmployee = $this->service->activate($employee);
    $user = $activeEmployee->user;

    expect($user)->toBeInstanceOf(User::class);

    givePermissionWithTenant($user, $this->tenant->id, 'employee.delete');

    $onLeaveEmployee = $this->service->placeOnLeave($activeEmployee);
    $restoredEmployee = $this->service->returnFromLeave($onLeaveEmployee);

    $user->refresh();

    expect($restoredEmployee->status)->toBe(Employee::STATUS_ACTIVE);
    expect($restoredEmployee->runtime_access_snapshot)->toBeNull();
    expect($user->hasRole('Employee'))->toBeTrue();
    expect($user->hasRole('Employee Read Only'))->toBeFalse();
    expect($user->can('employee.read'))->toBeTrue();
    expect($user->can('employee.update'))->toBeTrue();
    expect($user->can('employee.delete'))->toBeTrue();
});

test('employee lifecycle service clears on-leave access snapshots and direct permissions on termination', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'contract_start_date' => now()->subWeek(),
        'termination_date' => now()->toDateString(),
    ]);

    $activeEmployee = $this->service->activate($employee);
    $user = $activeEmployee->user;

    expect($user)->toBeInstanceOf(User::class);

    givePermissionWithTenant($user, $this->tenant->id, 'employee.delete');

    $onLeaveEmployee = $this->service->placeOnLeave($activeEmployee);
    $terminatedEmployee = $this->service->terminate($onLeaveEmployee);

    $user->refresh();

    expect($terminatedEmployee->status)->toBe(Employee::STATUS_TERMINATED);
    expect($terminatedEmployee->runtime_access_snapshot)->toBeNull();
    expect(DB::table('model_has_roles')->where('model_id', $user->id)->count())->toBe(0);
    expect(DB::table('model_has_permissions')->where('model_id', $user->id)->count())->toBe(0);
    expect($user->can('employee.delete'))->toBeFalse();
});

test('employee lifecycle service deletes an employee without a linked user account', function (): void {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'user_id' => null,
        'user_account_active' => false,
    ]);

    $deletedEmployee = $this->service->delete($employee);

    expect($deletedEmployee->deleted_at)->not->toBeNull()
        ->and(Employee::withTrashed()->find($employee->id))->not->toBeNull()
        ->and(Employee::withTrashed()->find($employee->id)?->user_id)->toBeNull();
});

test('employee lifecycle service deletes a linked user while preserving employee audit records', function (): void {
    $linkedUser = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'user_id' => $linkedUser->id,
        'user_account_active' => true,
    ]);

    $document = EmployeeDocument::factory()->create([
        'employee_id' => $employee->id,
        'uploaded_by' => $linkedUser->id,
    ]);

    $template = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $template->id,
        'status' => 'approved',
        'reviewed_by' => $linkedUser->id,
        'reviewed_at' => now(),
    ]);

    $submissionFile = OnboardingSubmissionFile::create([
        'onboarding_form_submission_id' => $submission->id,
        'uploaded_by' => $linkedUser->id,
        'document_type' => 'contract',
        'file_path' => 'employees/'.$employee->id.'/onboarding/contract.pdf',
        'file_name' => 'contract.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
    ]);

    $roleAssignmentLog = RoleAssignmentLog::create([
        'user_id' => $linkedUser->id,
        'role_id' => Role::findByName('Employee', 'sanctum')->id,
        'action' => 'assigned',
        'valid_from' => now()->subDay(),
        'assigned_by' => $linkedUser->id,
        'reason' => 'Lifecycle test coverage',
    ]);

    $customer = Customer::factory()->forTenant($this->tenant->id)->create();
    $site = Site::factory()
        ->forTenant($this->tenant->id)
        ->forCustomer($customer)
        ->forOrganizationalUnit($this->orgUnit)
        ->create();

    $expiredCustomerAssignment = CustomerAssignment::factory()
        ->for($customer)
        ->for($linkedUser)
        ->expired()
        ->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'Former Account Lead',
        ]);

    $activeCustomerAssignment = CustomerAssignment::factory()
        ->for($customer)
        ->for($linkedUser)
        ->active()
        ->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'Current Account Lead',
        ]);

    $expiredSiteAssignment = SiteAssignment::factory()
        ->for($site)
        ->for($linkedUser)
        ->expired()
        ->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'Former Site Lead',
        ]);

    $activeSiteAssignment = SiteAssignment::factory()
        ->for($site)
        ->for($linkedUser)
        ->active()
        ->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'Current Site Lead',
        ]);

    $deletedEmployee = $this->service->delete($employee);

    expect($deletedEmployee->deleted_at)->not->toBeNull()
        ->and(User::query()->find($linkedUser->id))->toBeNull()
        ->and(EmployeeDocument::query()->find($document->id)?->uploaded_by)->toBeNull()
        ->and(OnboardingFormSubmission::query()->find($submission->id)?->reviewed_by)->toBeNull()
        ->and(OnboardingSubmissionFile::query()->find($submissionFile->id)?->uploaded_by)->toBeNull()
        ->and(RoleAssignmentLog::query()->find($roleAssignmentLog->id))->not->toBeNull()
        ->and(RoleAssignmentLog::query()->find($roleAssignmentLog->id)?->user_id)->toBeNull()
        ->and(CustomerAssignment::query()->find($expiredCustomerAssignment->id))->not->toBeNull()
        ->and(CustomerAssignment::query()->find($expiredCustomerAssignment->id)?->user_id)->toBeNull()
        ->and(CustomerAssignment::query()->find($expiredCustomerAssignment->id)?->valid_until?->toDateString())->toBe($expiredCustomerAssignment->valid_until?->toDateString())
        ->and(CustomerAssignment::query()->find($activeCustomerAssignment->id))->not->toBeNull()
        ->and(CustomerAssignment::query()->find($activeCustomerAssignment->id)?->user_id)->toBeNull()
        ->and(CustomerAssignment::query()->find($activeCustomerAssignment->id)?->valid_until?->isPast())->toBeTrue()
        ->and(SiteAssignment::query()->find($expiredSiteAssignment->id))->not->toBeNull()
        ->and(SiteAssignment::query()->find($expiredSiteAssignment->id)?->user_id)->toBeNull()
        ->and(SiteAssignment::query()->find($expiredSiteAssignment->id)?->valid_until?->toDateString())->toBe($expiredSiteAssignment->valid_until?->toDateString())
        ->and(SiteAssignment::query()->find($activeSiteAssignment->id))->not->toBeNull()
        ->and(SiteAssignment::query()->find($activeSiteAssignment->id)?->user_id)->toBeNull()
        ->and(SiteAssignment::query()->find($activeSiteAssignment->id)?->valid_until?->isPast())->toBeTrue();
});

test('employee lifecycle service preserves causer rank context without invalidating activity hashes', function (): void {
    $linkedUser = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'user_id' => $linkedUser->id,
        'user_account_active' => true,
        'management_level' => 3,
    ]);

    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $linkedUser->id,
        'properties' => [
            'original_context' => 'unchanged',
        ],
    ]);

    expect($activity->refresh()->verifyChain())->toBeTrue();

    $this->service->delete($employee);

    $activity->refresh();

    expect($activity->properties)->toMatchArray([
        'original_context' => 'unchanged',
    ])
        ->and($activity->verifyChain())->toBeTrue();
});

test('employee lifecycle service does not synthesize missing causer rank snapshots at deletion time', function (): void {
    $otherUnit = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Other Department',
        'code' => 'OTHER',
        'type' => 'department',
        'is_active' => true,
    ]);

    $linkedUser = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'user_id' => $linkedUser->id,
        'user_account_active' => true,
        'management_level' => 3,
    ]);

    // Legacy activity rows have no trustworthy per-event rank snapshot.
    $ownUnitActivity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $linkedUser->id,
    ]);
    DB::table('activity_log')->where('id', $ownUnitActivity->id)->update([
        'causer_employee_id' => null,
        'causer_employee_organizational_unit_id' => null,
        'causer_employee_management_level' => null,
    ]);

    $otherUnitActivity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $otherUnit->id,
        'causer_type' => User::class,
        'causer_id' => $linkedUser->id,
    ]);
    DB::table('activity_log')->where('id', $otherUnitActivity->id)->update([
        'causer_employee_id' => null,
        'causer_employee_organizational_unit_id' => null,
        'causer_employee_management_level' => null,
    ]);

    $globalActivity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => null,
        'causer_type' => User::class,
        'causer_id' => $linkedUser->id,
    ]);
    DB::table('activity_log')->where('id', $globalActivity->id)->update([
        'causer_employee_id' => null,
        'causer_employee_organizational_unit_id' => null,
        'causer_employee_management_level' => null,
    ]);

    $this->service->delete($employee);

    $ownUnit = Activity::query()->findOrFail($ownUnitActivity->id);
    $other = Activity::query()->findOrFail($otherUnitActivity->id);
    $global = Activity::query()->findOrFail($globalActivity->id);

    expect($ownUnit->causer_employee_id)->toBeNull()
        ->and($ownUnit->causer_employee_organizational_unit_id)->toBeNull()
        ->and($ownUnit->causer_employee_management_level)->toBeNull()
        ->and($other->causer_employee_id)->toBeNull()
        ->and($other->causer_employee_organizational_unit_id)->toBeNull()
        ->and($other->causer_employee_management_level)->toBeNull()
        ->and($global->causer_employee_id)->toBeNull()
        ->and($global->causer_employee_organizational_unit_id)->toBeNull()
        ->and($global->causer_employee_management_level)->toBeNull();
});

test('employee lifecycle service keeps a user account when another employee still references it', function (): void {
    $linkedUser = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $deletedEmployee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'user_id' => $linkedUser->id,
        'user_account_active' => true,
    ]);

    $remainingEmployee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_ACTIVE,
        'user_id' => $linkedUser->id,
        'user_account_active' => true,
    ]);

    $result = $this->service->delete($deletedEmployee);

    expect($result->deleted_at)->not->toBeNull()
        ->and(User::query()->find($linkedUser->id))->not->toBeNull()
        ->and(Employee::withTrashed()->find($deletedEmployee->id)?->user_id)->toBeNull()
        ->and(Employee::query()->findOrFail($remainingEmployee->id)->user_id)->toBe($linkedUser->id);
});

test('employee lifecycle service deprovisions a shared user when only trashed employees still reference it', function (): void {
    $linkedUser = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'remember_token' => 'remember-me',
    ]);

    giveRoleWithTenant($linkedUser, $this->tenant->id, 'Employee');
    givePermissionWithTenant($linkedUser, $this->tenant->id, 'employee.delete');
    $linkedUser->createToken('legacy-token');

    DB::table('sessions')->insert([
        'id' => 'legacy-shared-user-session',
        'user_id' => $linkedUser->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => base64_encode('test'),
        'last_activity' => now()->timestamp,
    ]);

    $deletedEmployee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'user_id' => $linkedUser->id,
        'user_account_active' => true,
    ]);

    $trashedEmployee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_TERMINATED,
        'user_id' => $linkedUser->id,
        'user_account_active' => false,
    ]);
    $trashedEmployee->delete();

    $result = $this->service->delete($deletedEmployee);

    expect($result->deleted_at)->not->toBeNull()
        ->and(User::query()->find($linkedUser->id))->not->toBeNull()
        ->and(Employee::withTrashed()->find($deletedEmployee->id)?->user_id)->toBeNull()
        ->and(Employee::withTrashed()->findOrFail($trashedEmployee->id)->user_id)->toBe($linkedUser->id)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $linkedUser->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $linkedUser->id)->count())->toBe(0)
        ->and(DB::table('model_has_roles')->where('model_id', $linkedUser->id)->count())->toBe(0)
        ->and(DB::table('model_has_permissions')->where('model_id', $linkedUser->id)->count())->toBe(0)
        ->and(User::query()->findOrFail($linkedUser->id)->remember_token)->toBeNull();
});

test('employee lifecycle service cancels future assignments when deleting a linked user', function (): void {
    $linkedUser = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'user_id' => $linkedUser->id,
        'user_account_active' => true,
    ]);

    $customer = Customer::factory()->forTenant($this->tenant->id)->create();
    $site = Site::factory()
        ->forTenant($this->tenant->id)
        ->forCustomer($customer)
        ->forOrganizationalUnit($this->orgUnit)
        ->create();

    $futureStart = now()->addWeek()->toDateString();

    $futureCustomerAssignment = CustomerAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $customer->id,
        'user_id' => $linkedUser->id,
        'role' => 'Planned Account Lead',
        'valid_from' => $futureStart,
        'valid_until' => null,
    ]);

    $futureSiteAssignment = SiteAssignment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_id' => $site->id,
        'user_id' => $linkedUser->id,
        'role' => 'Planned Site Lead',
        'valid_from' => $futureStart,
        'valid_until' => null,
    ]);

    $deletedEmployee = $this->service->delete($employee);

    expect($deletedEmployee->deleted_at)->not->toBeNull()
        ->and(CustomerAssignment::query()->find($futureCustomerAssignment->id))->toBeNull()
        ->and(SiteAssignment::query()->find($futureSiteAssignment->id))->toBeNull();
});

test('employee lifecycle service rolls leave transition back when the read-only role is missing', function () {
    Mail::fake();

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'contract_start_date' => now()->subWeek(),
    ]);

    $activeEmployee = $this->service->activate($employee);

    Role::where('name', 'Employee Read Only')->delete();

    expect(fn () => $this->service->placeOnLeave($activeEmployee))
        ->toThrow(RuntimeException::class, 'Role "Employee Read Only" not found.');

    $activeEmployee->refresh();

    expect($activeEmployee->status)->toBe(Employee::STATUS_ACTIVE);
    expect($activeEmployee->runtime_access_snapshot)->toBeNull();
});
