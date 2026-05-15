<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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

function loadResidentialAddressHistoryMigration(): object
{
    return require database_path('migrations/2026_05_12_120000_add_residential_address_history_onboarding_step.php');
}

function markGlobalResidentialTemplateAsPreExisting(array $attributes = []): OnboardingFormTemplate
{
    $template = OnboardingFormTemplate::query()
        ->whereNull('tenant_id')
        ->where('template_key', 'residential_address_history')
        ->firstOrFail();

    $newId = (string) Str::uuid();

    Illuminate\Support\Facades\DB::table('onboarding_form_templates')
        ->where('id', $template->id)
        ->update([
            'id' => $newId,
            'created_at' => now()->subDay()->startOfSecond(),
            'updated_at' => now()->subHours(2)->startOfSecond(),
            ...$attributes,
        ]);

    return OnboardingFormTemplate::query()->findOrFail($newId);
}

test('migration reopens completed pre-contract onboarding when the new required step is inserted', function (): void {
    $completedAt = now()->subDay()->startOfSecond();

    $employee = Employee::factory()->preContract()->create([
        'onboarding_completed' => true,
        'onboarding_completed_at' => $completedAt,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'onboarding_steps' => [
            'steps' => [
                [
                    'id' => 'personal_data',
                    'name' => 'Persönliche Daten',
                    'completed' => true,
                    'completed_at' => now()->subDays(2)->toIso8601String(),
                    'form_submission_id' => 'personal-submission',
                ],
                [
                    'id' => 'bank_details',
                    'name' => 'Bankverbindung',
                    'completed' => true,
                    'completed_at' => now()->subDay()->toIso8601String(),
                    'form_submission_id' => 'bank-submission',
                ],
            ],
        ],
    ]);

    $migration = loadResidentialAddressHistoryMigration();
    $migration->up();

    $employee->refresh();

    expect(array_column($employee->onboarding_steps['steps'], 'id'))->toBe([
        'personal_data',
        'residential_address_history',
        'bank_details',
    ])
        ->and($employee->onboarding_completed)->toBeFalse()
        ->and($employee->onboarding_completed_at?->toISOString())->toBe($completedAt->toISOString())
        ->and($employee->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION)
        ->and($employee->canTransitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_IN_PROGRESS))->toBeTrue()
        ->and($employee->canTransitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW))->toBeTrue();
});

test('migration reopens completed contract-confirmed onboarding without blocking the new required step', function (): void {
    $completedAt = now()->subDay()->startOfSecond();

    $employee = Employee::factory()->preContract()->create([
        'onboarding_completed' => true,
        'onboarding_completed_at' => $completedAt,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED,
        'contract_start_date' => now()->addWeek()->toDateString(),
        'onboarding_steps' => [
            'steps' => [
                [
                    'id' => 'personal_data',
                    'name' => 'Persönliche Daten',
                    'completed' => true,
                    'completed_at' => now()->subDays(2)->toIso8601String(),
                    'form_submission_id' => 'personal-submission',
                ],
                [
                    'id' => 'bank_details',
                    'name' => 'Bankverbindung',
                    'completed' => true,
                    'completed_at' => now()->subDay()->toIso8601String(),
                    'form_submission_id' => 'bank-submission',
                ],
            ],
        ],
    ]);

    $migration = loadResidentialAddressHistoryMigration();
    $migration->up();

    $employee->refresh();

    expect(array_column($employee->onboarding_steps['steps'], 'id'))->toBe([
        'personal_data',
        'residential_address_history',
        'bank_details',
    ])
        ->and($employee->onboarding_completed)->toBeFalse()
        ->and($employee->onboarding_completed_at?->toISOString())->toBe($completedAt->toISOString())
        ->and($employee->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED)
        ->and($employee->canTransitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_IN_PROGRESS))->toBeTrue()
        ->and($employee->canTransitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW))->toBeTrue();
});

test('migration preserves an existing completed residential address history step', function (): void {
    $completedAt = now()->subHour()->toIso8601String();

    $employee = Employee::factory()->preContract()->create([
        'onboarding_steps' => [
            'steps' => [
                [
                    'id' => 'personal_data',
                    'name' => 'Persönliche Daten',
                    'completed' => true,
                    'completed_at' => now()->subDay()->toIso8601String(),
                    'form_submission_id' => 'personal-submission',
                ],
                [
                    'id' => 'residential_address_history',
                    'name' => 'Wohnanschriften',
                    'completed' => true,
                    'completed_at' => $completedAt,
                    'form_submission_id' => 'address-submission',
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

    $migration = loadResidentialAddressHistoryMigration();
    $migration->up();

    $employee->refresh();

    $residentialStep = collect($employee->onboarding_steps['steps'])
        ->firstWhere('id', 'residential_address_history');

    expect($residentialStep)->toBe([
        'id' => 'residential_address_history',
        'name' => 'Wohnanschriften',
        'completed' => true,
        'completed_at' => $completedAt,
        'form_submission_id' => 'address-submission',
    ]);
});

test('migration only reorders global templates and rollback preserves tenant residential templates and submissions', function (): void {
    $tenant = TenantKey::factory()->create();

    $globalLegacyTemplate = OnboardingFormTemplate::factory()->systemTemplate()->create([
        'name' => 'Legacy Global Template',
        'template_key' => null,
        'sort_order' => 2,
    ]);

    $tenantTemplate = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Tenant Custom Template',
        'template_key' => null,
        'sort_order' => 2,
    ]);

    $tenantResidentialTemplate = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Tenant Residential Address History',
        'template_key' => 'residential_address_history',
        'sort_order' => 7,
    ]);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $tenantSubmission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $tenantResidentialTemplate->id,
        'status' => 'draft',
    ]);

    $migration = loadResidentialAddressHistoryMigration();
    $migration->up();

    expect($globalLegacyTemplate->fresh()->sort_order)->toBe(3)
        ->and($tenantTemplate->fresh()->sort_order)->toBe(2)
        ->and($tenantResidentialTemplate->fresh())->not->toBeNull()
        ->and($tenantSubmission->fresh())->not->toBeNull();

    $migration->down();

    expect($globalLegacyTemplate->fresh()->sort_order)->toBe(2)
        ->and($tenantTemplate->fresh()->sort_order)->toBe(2)
        ->and($tenantResidentialTemplate->fresh())->not->toBeNull()
        ->and($tenantSubmission->fresh())->not->toBeNull();
});

test('rollback preserves pre-existing global residential templates and submissions', function (): void {
    $employee = Employee::factory()->preContract()->create();

    $existingGlobalTemplate = markGlobalResidentialTemplateAsPreExisting();

    $existingSubmission = OnboardingFormSubmission::factory()->submitted()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $existingGlobalTemplate->id,
    ]);

    $migration = loadResidentialAddressHistoryMigration();
    $migration->up();
    $migration->down();

    expect($existingGlobalTemplate->fresh())->not->toBeNull()
        ->and($existingSubmission->fresh())->not->toBeNull();
});

test('rollback does not delete a pre-existing global residential template created in a single timestamp tick', function (): void {
    $employee = Employee::factory()->preContract()->create();

    $sameTimestamp = now()->subHour()->startOfSecond();

    $existingGlobalTemplate = markGlobalResidentialTemplateAsPreExisting([
        'created_at' => $sameTimestamp,
        'updated_at' => $sameTimestamp,
    ]);

    $existingSubmission = OnboardingFormSubmission::factory()->submitted()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $existingGlobalTemplate->id,
    ]);

    $migration = loadResidentialAddressHistoryMigration();
    $migration->up();
    $migration->down();

    expect($existingGlobalTemplate->fresh())->not->toBeNull()
        ->and($existingSubmission->fresh())->not->toBeNull();
});

test('migration does not mutate a pre-existing global residential template definition', function (): void {
    $originalSchema = ['type' => 'object', 'properties' => ['legacy_field' => ['type' => 'string']]];

    $existingGlobalTemplate = markGlobalResidentialTemplateAsPreExisting([
        'name' => 'Legacy Residential Template',
        'description' => 'Legacy description',
        'form_schema' => $originalSchema,
        'sort_order' => 5,
    ]);

    $migration = loadResidentialAddressHistoryMigration();
    $migration->up();
    $migration->down();

    $existingGlobalTemplate->refresh();

    expect($existingGlobalTemplate->name)->toBe('Legacy Residential Template')
        ->and($existingGlobalTemplate->description)->toBe('Legacy description')
        ->and($existingGlobalTemplate->form_schema)->toBe($originalSchema)
        ->and($existingGlobalTemplate->sort_order)->toBe(5);
});

test('rollback restores reopened completed dossiers to a complete reviewable state', function (): void {
    $completedAt = now()->subDay()->startOfSecond();

    $employee = Employee::factory()->preContract()->create([
        'onboarding_completed' => true,
        'onboarding_completed_at' => $completedAt,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'onboarding_steps' => [
            'steps' => [
                [
                    'id' => 'personal_data',
                    'name' => 'Persönliche Daten',
                    'completed' => true,
                    'completed_at' => now()->subDays(2)->toIso8601String(),
                    'form_submission_id' => 'personal-submission',
                ],
                [
                    'id' => 'bank_details',
                    'name' => 'Bankverbindung',
                    'completed' => true,
                    'completed_at' => now()->subDay()->toIso8601String(),
                    'form_submission_id' => 'bank-submission',
                ],
            ],
        ],
    ]);

    $migration = loadResidentialAddressHistoryMigration();
    $migration->up();
    $migration->down();

    $employee->refresh();

    expect(array_column($employee->onboarding_steps['steps'], 'id'))->toBe([
        'personal_data',
        'bank_details',
    ])
        ->and($employee->onboarding_completed)->toBeTrue()
        ->and($employee->onboarding_completed_at?->toISOString())->toBe($completedAt->toISOString())
        ->and($employee->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION);
});

test('rollback keeps pre-existing residential address history employee steps when the global template predated the migration', function (): void {
    $employee = Employee::factory()->preContract()->create([
        'onboarding_steps' => [
            'steps' => [
                [
                    'id' => 'personal_data',
                    'name' => 'Persönliche Daten',
                    'completed' => true,
                    'completed_at' => now()->subDay()->toIso8601String(),
                    'form_submission_id' => 'personal-submission',
                ],
                [
                    'id' => 'residential_address_history',
                    'name' => 'Wohnanschriften',
                    'completed' => true,
                    'completed_at' => now()->subHour()->toIso8601String(),
                    'form_submission_id' => 'address-submission',
                ],
            ],
        ],
    ]);

    markGlobalResidentialTemplateAsPreExisting();

    $migration = loadResidentialAddressHistoryMigration();
    $migration->up();
    $migration->down();

    $employee->refresh();

    expect(collect($employee->onboarding_steps['steps'])
        ->where('id', 'residential_address_history')
        ->count())->toBe(1);
});

test('rollback removes empty migration-inserted residential steps even when the global template predated the migration', function (): void {
    $employee = Employee::factory()->preContract()->create([
        'onboarding_completed' => true,
        'onboarding_completed_at' => now()->subDay(),
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'onboarding_steps' => [
            'steps' => [
                [
                    'id' => 'personal_data',
                    'name' => 'Persönliche Daten',
                    'completed' => true,
                    'completed_at' => now()->subDays(2)->toIso8601String(),
                    'form_submission_id' => 'personal-submission',
                ],
                [
                    'id' => 'bank_details',
                    'name' => 'Bankverbindung',
                    'completed' => true,
                    'completed_at' => now()->subDay()->toIso8601String(),
                    'form_submission_id' => 'bank-submission',
                ],
            ],
        ],
    ]);

    markGlobalResidentialTemplateAsPreExisting();

    $migration = loadResidentialAddressHistoryMigration();
    $migration->up();
    $migration->down();

    $employee->refresh();

    expect(array_column($employee->onboarding_steps['steps'], 'id'))->toBe([
        'personal_data',
        'bank_details',
    ])
        ->and($employee->onboarding_completed)->toBeTrue()
        ->and($employee->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION);
});

test('rollback is refused once residential address submissions exist for the inserted global template', function (): void {
    $employee = Employee::factory()->preContract()->create();

    $insertedTemplate = OnboardingFormTemplate::query()
        ->whereNull('tenant_id')
        ->where('template_key', 'residential_address_history')
        ->firstOrFail();

    OnboardingFormSubmission::factory()->submitted()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $insertedTemplate->id,
    ]);

    $migration = loadResidentialAddressHistoryMigration();

    expect(fn (): mixed => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot rollback residential address history migration after submissions exist.');
});
