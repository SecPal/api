<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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

test('migration inserts nationality and residence into existing onboarding steps', function (): void {
    $completedAt = now()->subHour()->toIso8601String();

    $employee = Employee::factory()->preContract()->create([
        'onboarding_steps' => [
            'steps' => [
                [
                    'id' => 'personal_data',
                    'name' => 'Persönliche Daten',
                    'completed' => true,
                    'completed_at' => $completedAt,
                    'form_submission_id' => 'legacy-personal-submission',
                ],
                [
                    'id' => 'bank_details',
                    'name' => 'Bankverbindung',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
                [
                    'id' => 'tax_info',
                    'name' => 'Steuerinformationen',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
                [
                    'id' => 'qualifications',
                    'name' => 'Qualifikationen & Zertifikate',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
                [
                    'id' => 'documents',
                    'name' => 'Dokumente hochladen',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
                [
                    'id' => 'confirmation',
                    'name' => 'Bestätigung & Abschluss',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
            ],
        ],
    ]);

    $migration = loadSplitNationalityResidenceMigration();
    $migration->up();

    $employee->refresh();

    expect(array_column($employee->onboarding_steps['steps'], 'id'))->toBe([
        'personal_data',
        'nationality_and_residence',
        'bank_details',
        'tax_info',
        'qualifications',
        'documents',
        'confirmation',
    ])
        ->and($employee->onboarding_steps['steps'][0])->toBe([
            'id' => 'personal_data',
            'name' => 'Persönliche Daten',
            'completed' => true,
            'completed_at' => $completedAt,
            'form_submission_id' => 'legacy-personal-submission',
        ])
        ->and($employee->onboarding_steps['steps'][1])->toBe([
            'id' => 'nationality_and_residence',
            'name' => 'Staatsangehörigkeit und Aufenthalt',
            'completed' => false,
            'completed_at' => null,
            'form_submission_id' => null,
        ]);
});

test('migration does not duplicate nationality and residence when the step already exists', function (): void {
    $employee = Employee::factory()->preContract()->create([
        'onboarding_steps' => [
            'steps' => [
                [
                    'id' => 'personal_data',
                    'name' => 'Persönliche Daten',
                    'completed' => true,
                    'completed_at' => now()->subHour()->toIso8601String(),
                    'form_submission_id' => 'legacy-personal-submission',
                ],
                [
                    'id' => 'nationality_and_residence',
                    'name' => 'Staatsangehörigkeit und Aufenthalt',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
                [
                    'id' => 'bank_details',
                    'name' => 'Bankverbindung',
                    'completed' => false,
                    'completed_at' => null,
                    'form_submission_id' => null,
                ],
            ],
        ],
    ]);

    $migration = loadSplitNationalityResidenceMigration();
    $migration->up();

    $employee->refresh();

    expect(array_column($employee->onboarding_steps['steps'], 'id'))->toBe([
        'personal_data',
        'nationality_and_residence',
        'bank_details',
    ])
        ->and(collect($employee->onboarding_steps['steps'])
            ->where('id', 'nationality_and_residence')
            ->count())->toBe(1);
});

test('migration marks migrated nationality and residence step complete when the migrated submission is already submitted', function (): void {
    $personalInformationTemplate = OnboardingFormTemplate::factory()->systemTemplate()->create([
        'template_key' => 'personal_information_form',
    ]);

    $employee = Employee::factory()->preContract()->create([
        'onboarding_steps' => [
            'steps' => [
                [
                    'id' => 'personal_data',
                    'name' => 'Persönliche Daten',
                    'completed' => true,
                    'completed_at' => now()->subHour()->toIso8601String(),
                    'form_submission_id' => 'legacy-personal-submission',
                ],
            ],
        ],
    ]);

    $legacySubmission = OnboardingFormSubmission::factory()->submitted()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $personalInformationTemplate->id,
        'form_data' => [
            'nationalities' => ['IN'],
            'residence_permit_title' => 'Aufenthaltserlaubnis',
            'residence_permit_employment_allowed' => 'yes',
            'residence_permit_unlimited' => true,
        ],
    ]);

    $migration = loadSplitNationalityResidenceMigration();
    $migration->up();

    $employee->refresh();

    $migratedStep = collect($employee->onboarding_steps['steps'])
        ->firstWhere('id', 'nationality_and_residence');

    $nationalityTemplate = OnboardingFormTemplate::query()
        ->where('template_key', 'nationality_and_residence')
        ->firstOrFail();

    $migratedSubmission = OnboardingFormSubmission::query()
        ->where('employee_id', $employee->id)
        ->where('form_template_id', $nationalityTemplate->id)
        ->firstOrFail();

    expect($migratedSubmission->status)->toBe('submitted')
        ->and($migratedStep)->toBe([
            'id' => 'nationality_and_residence',
            'name' => 'Staatsangehörigkeit und Aufenthalt',
            'completed' => true,
            'completed_at' => $legacySubmission->submitted_at?->toIso8601String(),
            'form_submission_id' => $migratedSubmission->id,
        ]);
});

test('migration does not reopen active employees when migrated residence data is incomplete', function (): void {
    $personalInformationTemplate = OnboardingFormTemplate::factory()->systemTemplate()->create([
        'template_key' => 'personal_information_form',
    ]);

    $employee = Employee::factory()->active()->create([
        'onboarding_completed' => true,
    ]);

    $legacySubmission = OnboardingFormSubmission::factory()->approved()->create([
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

    expect($migratedSubmission->status)->toBe('approved')
        ->and($migratedSubmission->submitted_at?->toIso8601String())->toBe($legacySubmission->submitted_at?->toIso8601String())
        ->and($migratedSubmission->reviewed_by)->toBe($legacySubmission->reviewed_by)
        ->and($migratedSubmission->reviewed_at?->toIso8601String())->toBe($legacySubmission->reviewed_at?->toIso8601String())
        ->and($employee->fresh()->onboarding_completed)->toBeTrue();
});

test('migration rollback merges nationality and residence submissions back into personal information', function (): void {
    $personalInformationTemplate = OnboardingFormTemplate::factory()->systemTemplate()->create([
        'template_key' => 'personal_information_form',
    ]);

    $nationalityTemplate = OnboardingFormTemplate::query()
        ->whereNull('tenant_id')
        ->where('template_key', 'nationality_and_residence')
        ->first()
        ?? OnboardingFormTemplate::factory()->systemTemplate()->create([
            'template_key' => 'nationality_and_residence',
        ]);

    $employee = Employee::factory()->preContract()->create();
    $expiryDate = now()->addMonth()->toDateString();

    OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $personalInformationTemplate->id,
        'form_data' => [
            'gender' => 'male',
        ],
        'status' => 'draft',
    ]);

    OnboardingFormSubmission::factory()->submitted()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $nationalityTemplate->id,
        'form_data' => [
            'nationalities' => ['IN'],
            'residence_permit_title' => 'Aufenthaltserlaubnis',
            'residence_permit_employment_allowed' => 'yes',
            'residence_permit_unlimited' => false,
            'residence_permit_expiry' => $expiryDate,
        ],
    ]);

    $migration = loadSplitNationalityResidenceMigration();
    $migration->down();

    $mergedSubmission = OnboardingFormSubmission::query()
        ->where('employee_id', $employee->id)
        ->where('form_template_id', $personalInformationTemplate->id)
        ->firstOrFail();

    expect($mergedSubmission->form_data)->toBe([
        'gender' => 'male',
        'nationalities' => ['IN'],
        'residence_permit_title' => 'Aufenthaltserlaubnis',
        'residence_permit_employment_allowed' => 'yes',
        'residence_permit_unlimited' => false,
        'residence_permit_expiry' => $expiryDate,
    ])
        ->and(OnboardingFormTemplate::query()
            ->where('template_key', 'nationality_and_residence')
            ->exists())->toBeFalse()
        ->and(OnboardingFormSubmission::query()
            ->where('employee_id', $employee->id)
            ->count())->toBe(1);
});
