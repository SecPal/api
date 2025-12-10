<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

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

uses(RefreshDatabase::class);

beforeEach(function (): void {
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

test('admin can view any onboarding form submissions', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    expect($this->policy->viewAny($admin))->toBeTrue();
});

test('manager can view any onboarding form submissions', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    expect($this->policy->viewAny($manager))->toBeTrue();
});

test('employee can view own submissions', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'pre_contract',
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'onboarding_form_template_id' => $template->id,
    ]);

    expect($this->policy->view($user, $submission))->toBeTrue();
});

test('employee cannot view other employees submissions', function (): void {
    $user = User::factory()->create();
    $otherEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => 'pre_contract',
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $otherEmployee->id,
        'onboarding_form_template_id' => $template->id,
    ]);

    expect($this->policy->view($user, $submission))->toBeFalse();
});

test('admin can view all submissions', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => 'pre_contract',
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'onboarding_form_template_id' => $template->id,
    ]);

    expect($this->policy->view($admin, $submission))->toBeTrue();
});

test('manager can view submissions in scope', function (): void {
    $orgUnit = OrganizationalUnit::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $manager->organizationalScopes()->create([
        'organizational_unit_id' => $orgUnit->id,
        'include_descendants' => false,
        'access_level' => 'write',
    ]);

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'organizational_unit_id' => $orgUnit->id,
        'status' => 'pre_contract',
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'onboarding_form_template_id' => $template->id,
    ]);

    expect($this->policy->view($manager, $submission))->toBeTrue();
});

test('only pre contract employees can create submissions', function (): void {
    $userWithPreContract = User::factory()->create();
    $preContractEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $userWithPreContract->id,
        'status' => 'pre_contract',
    ]);

    $userWithActiveEmployee = User::factory()->create();
    $activeEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $userWithActiveEmployee->id,
        'status' => 'active',
    ]);

    $userWithNoEmployee = User::factory()->create();

    expect($this->policy->create($userWithPreContract))->toBeTrue();
    expect($this->policy->create($userWithActiveEmployee))->toBeFalse();
    expect($this->policy->create($userWithNoEmployee))->toBeFalse();
});

test('employee can update own submissions', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'user_id' => $user->id,
        'status' => 'pre_contract',
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'onboarding_form_template_id' => $template->id,
    ]);

    expect($this->policy->update($user, $submission))->toBeTrue();
});

test('employee cannot update other employees submissions', function (): void {
    $user = User::factory()->create();
    $otherEmployee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => 'pre_contract',
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $otherEmployee->id,
        'onboarding_form_template_id' => $template->id,
    ]);

    expect($this->policy->update($user, $submission))->toBeFalse();
});

test('admin can update all submissions', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => 'pre_contract',
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'onboarding_form_template_id' => $template->id,
    ]);

    expect($this->policy->update($admin, $submission))->toBeTrue();
});

test('only admin can delete submissions', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $employee = Employee::factory()->for($this->tenant, 'tenant')->create([
        'status' => 'pre_contract',
    ]);
    $template = OnboardingFormTemplate::factory()->create();
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'onboarding_form_template_id' => $template->id,
    ]);

    expect($this->policy->delete($admin, $submission))->toBeTrue();
    expect($this->policy->delete($manager, $submission))->toBeFalse();
});
