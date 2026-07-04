<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\OnboardingFormSubmissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property OnboardingFormSubmissionPolicy $policy
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->policy = new OnboardingFormSubmissionPolicy;
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('pre-contract employees can view any submissions without onboarding.read', function (): void {
    $preContractUser = User::factory()->create();
    Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $preContractUser->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'onboarding.read');

    $userWithoutPermission = User::factory()->create();

    expect($this->policy->viewAny($preContractUser))->toBeTrue();
    expect($this->policy->viewAny($userWithPermission))->toBeTrue();
    expect($this->policy->viewAny($userWithoutPermission))->toBeFalse();
});

test('employee can view own submissions', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $template->id,
    ]);

    expect($this->policy->view($user, $submission))->toBeTrue();
});

test('employee cannot view other employees submissions', function (): void {
    $user = User::factory()->create();
    $otherEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $otherEmployee->id,
        'form_template_id' => $template->id,
    ]);

    expect($this->policy->view($user, $submission))->toBeFalse();
});

test('users with onboarding.read can view all submissions regardless of scope', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'onboarding.read');
    giveOrganizationalScope($userWithPermission, $orgUnit);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => Employee::STATUS_PRE_CONTRACT,
        'organizational_unit_id' => $orgUnit->id,
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $template->id,
    ]);

    expect($this->policy->view($userWithPermission, $submission))->toBeTrue();
});

test('users with onboarding.read and org scope can view submissions in scope', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $userWithScope = User::factory()->create();
    givePermissionWithTenant($userWithScope, $this->tenant->id, 'onboarding.read');

    $userWithScope->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $template->id,
    ]);

    expect($this->policy->view($userWithScope, $submission))->toBeTrue();
});

test('only pre contract employees can create submissions without onboarding.write', function (): void {
    $userWithPreContract = User::factory()->create();
    Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $userWithPreContract->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    $userWithActiveEmployee = User::factory()->create();
    Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $userWithActiveEmployee->id,
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $userWithNoEmployee = User::factory()->create();

    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'onboarding.write');
    Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $userWithPermission->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    expect($this->policy->create($userWithPreContract))->toBeTrue();
    expect($this->policy->create($userWithPermission))->toBeTrue();
    expect($this->policy->create($userWithActiveEmployee))->toBeFalse();
    expect($this->policy->create($userWithNoEmployee))->toBeFalse();
});

test('user with onboarding.write but non-pre-contract employee status cannot create submissions', function (): void {
    $userWithWriteOnly = User::factory()->create();
    givePermissionWithTenant($userWithWriteOnly, $this->tenant->id, 'onboarding.write');
    Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $userWithWriteOnly->id,
        'status' => Employee::STATUS_ACTIVE,
    ]);

    expect($this->policy->create($userWithWriteOnly))->toBeFalse();
});

test('pre-contract employee from a different tenant cannot create submissions in current tenant', function (): void {
    // Create a second tenant
    $otherKeys = TenantKey::generateEnvelopeKeys();
    $otherTenant = TenantKey::create($otherKeys);

    // User has a pre_contract employee record in the OTHER tenant only
    $userFromOtherTenant = User::factory()->create();
    Employee::factory()->for($otherTenant, 'tenant')->create([
        'user_id' => $userFromOtherTenant->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);

    // The user's own tenant_id is current tenant, but no employee record there
    $userFromOtherTenant->update(['tenant_id' => $this->tenant->id]);

    expect($this->policy->create($userFromOtherTenant))->toBeFalse();
});

test('employee can update own submissions without onboarding.write', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $template->id,
    ]);

    expect($this->policy->update($user, $submission))->toBeTrue();
});

test('employee cannot update other employees submissions', function (): void {
    $user = User::factory()->create();
    $otherEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $otherEmployee->id,
        'form_template_id' => $template->id,
    ]);

    expect($this->policy->update($user, $submission))->toBeFalse();
});

test('users with onboarding.write cannot update other employees submissions', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'onboarding.write');

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $template->id,
    ]);

    expect($this->policy->update($userWithPermission, $submission))->toBeFalse();
});

test('only users with onboarding.delete can delete submissions', function (): void {
    $userWithDelete = User::factory()->create();
    givePermissionWithTenant($userWithDelete, $this->tenant->id, 'onboarding.delete');

    $userWithWrite = User::factory()->create();
    givePermissionWithTenant($userWithWrite, $this->tenant->id, 'onboarding.write');

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => Employee::STATUS_PRE_CONTRACT,
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $template->id,
    ]);

    expect($this->policy->delete($userWithDelete, $submission))->toBeTrue();
    expect($this->policy->delete($userWithWrite, $submission))->toBeFalse();
});
