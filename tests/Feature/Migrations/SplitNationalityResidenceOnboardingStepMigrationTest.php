<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function loadSplitNationalityResidenceMigration(): object
{
    return require database_path('migrations/2026_05_05_200000_split_nationality_residence_onboarding_step.php');
}

test('migration preserves residence data for completed legacy submissions', function (): void {
    $personalInformationTemplate = OnboardingFormTemplate::factory()->systemTemplate()->create([
        'template_key' => 'personal_information_form',
    ]);

    $employee = Employee::factory()->preContract()->create();

    $legacySubmission = OnboardingFormSubmission::factory()->approved()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $personalInformationTemplate->id,
        'form_data' => [
            'nationalities' => ['IN'],
            'residence_permit_title' => 'Niederlassungserlaubnis',
            'residence_permit_employment_allowed' => 'yes',
            'residence_permit_unlimited' => true,
            'residence_permit_expiry' => now()->subDay()->toDateString(),
        ],
    ]);

    $migration = loadSplitNationalityResidenceMigration();
    $migration->up();

    $nationalityTemplate = OnboardingFormTemplate::query()
        ->where('template_key', 'nationality_and_residence')
        ->firstOrFail();

    $migratedSubmission = OnboardingFormSubmission::query()
        ->where('employee_id', $employee->id)
        ->where('form_template_id', $nationalityTemplate->id)
        ->firstOrFail();

    expect($migratedSubmission->form_data)->toBe([
        'nationalities' => ['IN'],
        'residence_permit_title' => 'Niederlassungserlaubnis',
        'residence_permit_employment_allowed' => 'yes',
        'residence_permit_unlimited' => true,
        'residence_permit_expiry' => $legacySubmission->form_data['residence_permit_expiry'],
    ])
        ->and($migratedSubmission->status)->toBe('approved')
        ->and($migratedSubmission->submitted_at?->toIso8601String())->toBe($legacySubmission->submitted_at?->toIso8601String())
        ->and($migratedSubmission->reviewed_by)->toBe($legacySubmission->reviewed_by)
        ->and($migratedSubmission->reviewed_at?->toIso8601String())->toBe($legacySubmission->reviewed_at?->toIso8601String())
        ->and($migratedSubmission->review_notes)->toBe($legacySubmission->review_notes);
});

test('migration resets completed legacy submissions when required residence data is missing', function (): void {
    $personalInformationTemplate = OnboardingFormTemplate::factory()->systemTemplate()->create([
        'template_key' => 'personal_information_form',
    ]);

    $employee = Employee::factory()->preContract()->create();

    OnboardingFormSubmission::factory()->approved()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $personalInformationTemplate->id,
        'form_data' => [
            'nationalities' => ['IN'],
        ],
    ]);

    $migration = loadSplitNationalityResidenceMigration();
    $migration->up();

    $nationalityTemplate = OnboardingFormTemplate::query()
        ->where('template_key', 'nationality_and_residence')
        ->firstOrFail();

    $migratedSubmission = OnboardingFormSubmission::query()
        ->where('employee_id', $employee->id)
        ->where('form_template_id', $nationalityTemplate->id)
        ->firstOrFail();

    expect($migratedSubmission->form_data)->toBe([
        'nationalities' => ['IN'],
    ])
        ->and($migratedSubmission->status)->toBe('draft')
        ->and($migratedSubmission->submitted_at)->toBeNull()
        ->and($migratedSubmission->reviewed_by)->toBeNull()
        ->and($migratedSubmission->reviewed_at)->toBeNull()
        ->and($migratedSubmission->review_notes)->toBeNull();
});

test('migration resets completed legacy submissions with mixed nationalities when residence data is missing', function (): void {
    $personalInformationTemplate = OnboardingFormTemplate::factory()->systemTemplate()->create([
        'template_key' => 'personal_information_form',
    ]);

    $employee = Employee::factory()->preContract()->create();

    OnboardingFormSubmission::factory()->approved()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $personalInformationTemplate->id,
        'form_data' => [
            'nationalities' => ['DE', 'IN'],
        ],
    ]);

    $migration = loadSplitNationalityResidenceMigration();
    $migration->up();

    $nationalityTemplate = OnboardingFormTemplate::query()
        ->where('template_key', 'nationality_and_residence')
        ->firstOrFail();

    $migratedSubmission = OnboardingFormSubmission::query()
        ->where('employee_id', $employee->id)
        ->where('form_template_id', $nationalityTemplate->id)
        ->firstOrFail();

    expect($migratedSubmission->form_data)->toBe([
        'nationalities' => ['DE', 'IN'],
    ])
        ->and($migratedSubmission->status)->toBe('draft')
        ->and($migratedSubmission->submitted_at)->toBeNull()
        ->and($migratedSubmission->reviewed_by)->toBeNull()
        ->and($migratedSubmission->reviewed_at)->toBeNull()
        ->and($migratedSubmission->review_notes)->toBeNull();
});

test('migration resets employee workflow to in progress when a migrated submission must go back to draft', function (): void {
    $personalInformationTemplate = OnboardingFormTemplate::factory()->systemTemplate()->create([
        'template_key' => 'personal_information_form',
    ]);

    $employee = Employee::factory()->preContract()->create([
        'onboarding_completed' => true,
        'onboarding_completed_at' => now(),
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
    ]);

    OnboardingFormSubmission::factory()->approved()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $personalInformationTemplate->id,
        'form_data' => [
            'nationalities' => ['IN'],
        ],
    ]);

    $migration = loadSplitNationalityResidenceMigration();
    $migration->up();

    $employee->refresh();

    expect($employee->onboarding_completed)->toBeFalse()
        ->and($employee->onboarding_completed_at)->toBeNull()
        ->and($employee->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_IN_PROGRESS)
        ->and($employee->canTransitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW))->toBeTrue();
});
