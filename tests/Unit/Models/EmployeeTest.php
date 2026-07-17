<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\EmployeeDocument;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use App\Observers\EmployeeObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('unit', 'models', 'employee');

/**
 * @property TenantKey $tenant
 */
beforeEach(function () {
    incrementTestKekCounter();
    // Create KEK and tenant (no factory for TenantKey)
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('employee model encrypts and decrypts personal data using enc fields', function () {
    $employee = Employee::withoutEvents(function () {
        return Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'date_of_birth' => '1990-05-15',
        ]);
    });

    // Check encrypted fields exist in database
    expect($employee->getAttributeValue('first_name_enc'))->not->toBeNull();
    expect($employee->getAttributeValue('last_name_enc'))->not->toBeNull();
    expect($employee->getAttributeValue('date_of_birth_enc'))->not->toBeNull();

    // Check accessors decrypt correctly
    expect($employee->first_name)->toBe('Max');
    expect($employee->last_name)->toBe('Mustermann');
    expect($employee->date_of_birth)->toBe('1990-05-15');
});

test('employee encrypts tax id and social security number', function () {
    $employee = Employee::withoutEvents(function () {
        return Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'tax_id' => '12345678901',
            'social_security_number' => '65 123456 A 123',
        ]);
    });

    // Check encrypted fields exist in database
    expect($employee->getAttributeValue('tax_id_enc'))->not->toBeNull();
    expect($employee->getAttributeValue('social_security_number_enc'))->not->toBeNull();

    // Check accessors decrypt correctly
    expect($employee->tax_id)->toBe('12345678901');
    expect($employee->social_security_number)->toBe('65 123456 A 123');

    // Ensure encrypted values in DB are JSON (not plaintext)
    $rawTaxId = $employee->getAttributes()['tax_id_enc'];
    $rawSsn = $employee->getAttributes()['social_security_number_enc'];

    expect($rawTaxId)->toContain('"ciphertext"');
    expect($rawTaxId)->toContain('"nonce"');
    expect($rawSsn)->toContain('"ciphertext"');
    expect($rawSsn)->toContain('"nonce"');
});

test('employee model encrypts and decrypts phone data', function () {
    $employee = Employee::withoutEvents(function () {
        return Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'phone' => '+49 30 12345678',
        ]);
    });

    expect($employee->getAttributeValue('phone_enc'))->not->toBeNull();
    expect($employee->phone)->toBe('+49 30 12345678');

    $rawPhone = $employee->getAttributes()['phone_enc'];

    expect($rawPhone)->toContain('"ciphertext"');
    expect($rawPhone)->toContain('"nonce"');
    expect($rawPhone)->not->toContain('+49 30 12345678');
});

test('employee model generates blind indexes for searchable encrypted fields', function () {
    // Create employee and trigger blind index generation via the model's observer
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Anna',
        'last_name' => 'Schmidt',
        'phone' => '+49 (30) 1234-5678',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    // Check blind indexes are generated (base64-encoded SHA256 HMAC = 44 chars)
    expect($employee->first_name_idx)->not->toBeNull();
    expect($employee->last_name_idx)->not->toBeNull();
    expect($employee->phone_idx)->not->toBeNull();
    expect(strlen($employee->first_name_idx))->toBe(44);
    expect(strlen($employee->last_name_idx))->toBe(44);
    expect(strlen($employee->phone_idx))->toBe(44);
});

test('employee phone blind index normalizes formatting differences', function () {
    $observer = app(EmployeeObserver::class);

    $employeeA = Employee::factory()->make([
        'tenant_id' => $this->tenant->id,
        'phone' => '+49 30 12345678',
    ]);
    $observer->creating($employeeA);

    $employeeB = Employee::factory()->make([
        'tenant_id' => $this->tenant->id,
        'phone' => '+49 (30) 1234 5678',
    ]);
    $observer->creating($employeeB);

    expect($employeeA->phone_idx)->toBe($employeeB->phone_idx);
});

test('employees schema stores encrypted phone and no plaintext phone column', function () {
    $columns = collect(DB::select(
        <<<'SQL'
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'employees'
          AND column_name IN ('phone', 'phone_enc', 'phone_idx')
        ORDER BY column_name
        SQL
    ))->pluck('column_name')->all();

    expect($columns)->toContain('phone_enc');
    expect($columns)->toContain('phone_idx');
    expect($columns)->not->toContain('phone');
});

test('employee date of birth accessor returns string not carbon object', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'date_of_birth' => '1985-12-25',
    ]);

    $dateOfBirth = $employee->date_of_birth;

    expect($dateOfBirth)->toBeString();
    expect($dateOfBirth)->toBe('1985-12-25');
});

test('employee full name accessor combines decrypted first and last names', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    expect($employee->full_name)->toBe('John Doe');
});

test('employee status state machine methods work correctly', function () {
    $preContract = Employee::factory()->create(['status' => 'pre_contract']);
    $active = Employee::factory()->create(['status' => 'active']);
    $terminated = Employee::factory()->create(['status' => 'terminated']);

    expect($preContract->isPreContract())->toBeTrue();
    expect($preContract->isActive())->toBeFalse();
    expect($preContract->isTerminated())->toBeFalse();

    expect($active->isActive())->toBeTrue();
    expect($active->isPreContract())->toBeFalse();

    expect($terminated->isTerminated())->toBeTrue();
    expect($terminated->isActive())->toBeFalse();
});

test('employee can activate when onboarding is complete, workflow is ready, and contract has started', function () {
    $employee = Employee::factory()->create([
        'status' => 'pre_contract',
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'contract_start_date' => now()->subDay(),
    ]);

    expect($employee->canActivate())->toBeTrue();
});

test('employee cannot activate when onboarding incomplete', function () {
    $employee = Employee::factory()->create([
        'status' => 'pre_contract',
        'onboarding_completed' => false,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
        'contract_start_date' => now()->subDay(),
    ]);

    expect($employee->canActivate())->toBeFalse();
});

test('employee cannot activate when onboarding workflow is not ready for activation', function () {
    $employee = Employee::factory()->create([
        'status' => 'pre_contract',
        'onboarding_completed' => true,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED,
        'contract_start_date' => now()->subDay(),
    ]);

    expect($employee->canActivate())->toBeFalse();
});

test('employee onboarding workflow transitions follow the allowed state machine', function () {
    $employee = Employee::factory()->create([
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED,
    ]);

    expect($employee->canTransitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_IN_PROGRESS))->toBeTrue();
    expect($employee->canTransitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW))->toBeTrue();
    expect($employee->canTransitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_ACTIVE))->toBeFalse();
});

test('employee normalizes authenticated onboarding workflow for invited pre-contract users', function () {
    $employee = Employee::factory()->create([
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_INVITED,
    ]);

    $normalized = $employee->normalizeAuthenticatedOnboardingWorkflow();

    expect($normalized->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED);
    expect($employee->fresh()->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED);
});

test('employee onboarding workflow readiness sync promotes contract confirmed employees with started contracts', function () {
    $employee = Employee::factory()->create([
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED,
        'contract_start_date' => now()->subDay(),
    ]);

    expect($employee->syncActivationReadinessWorkflow())->toBeTrue();
    expect($employee->fresh()->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION);
});

test('employee scopes filter correctly', function () {
    Employee::factory()->create(['status' => 'pre_contract']);
    Employee::factory()->create(['status' => 'active']);
    Employee::factory()->create(['status' => 'terminated']);
    Employee::factory()->create(['status' => 'on_leave']);

    expect(Employee::preContract()->count())->toBe(1);
    expect(Employee::active()->count())->toBe(1);
    expect(Employee::terminated()->count())->toBe(1);
    expect(Employee::onLeave()->count())->toBe(1);
});

test('get default onboarding steps returns consistent structure with completed at and form submission id', function () {
    $steps = Employee::getDefaultOnboardingSteps();

    expect($steps)->toBeArray();
    expect($steps)->toHaveKey('steps');
    expect($steps['steps'])->toBeArray();
    expect(array_column($steps['steps'], 'id'))->toBe([
        'personal_data',
        'residential_address_history',
        'nationality_and_residence',
        'bank_details',
        'emergency_contact',
        'tax_info',
        'qualifications',
        'documents',
        'confirmation',
    ]);

    foreach ($steps['steps'] as $step) {
        expect($step)->toHaveKey('id');
        expect($step)->toHaveKey('name');
        expect($step)->toHaveKey('completed');
        expect($step)->toHaveKey('completed_at');
        expect($step)->toHaveKey('form_submission_id');
        expect($step['completed'])->toBeFalse();
        expect($step['completed_at'])->toBeNull();
        expect($step['form_submission_id'])->toBeNull();
    }
});

test('employee relationships load correctly', function () {
    $legalEntity = LegalEntity::factory()->forTenant($this->tenant->id)->create();
    $establishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $user = User::factory()->create();

    $employee = Employee::factory()->create([
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $establishment->id,
        'user_id' => $user->id,
    ]);

    $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);
    $employee->qualifications()->attach($qualification->id, [
        'id' => Str::uuid()->toString(),
        'obtained_date' => now(),
    ]);

    EmployeeDocument::factory()->create(['employee_id' => $employee->id]);

    $employee->load(['user', 'legalEntity', 'establishment', 'qualifications', 'documents']);

    expect($employee->user)->toBeInstanceOf(User::class);
    expect($employee->legalEntity)->toBeInstanceOf(LegalEntity::class);
    expect($employee->establishment)->toBeInstanceOf(Establishment::class);
    expect($employee->qualifications)->toHaveCount(1);
    expect($employee->documents)->toHaveCount(1);
});

test('employee mutators trigger encryption', function () {
    $employee = Employee::factory()->make([
        'tenant_id' => $this->tenant->id,
    ]);

    // Test mutators set encrypted fields
    $employee->first_name = 'TestFirst';
    $employee->last_name = 'TestLast';
    $employee->date_of_birth = '1985-03-20';
    $employee->hourly_rate = 18.50;
    $employee->tax_id = '12345678901';
    $employee->social_security_number = '12 345678 A 123';

    $employee->save();

    EmployeeAddress::factory()->current()->create([
        'employee_id' => $employee->id,
        'tenant_id' => $employee->tenant_id,
        'street' => 'Test Street',
        'house_number' => '123',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'country' => 'DE',
    ]);

    $employee->refresh();

    // Verify values are encrypted and decrypted correctly
    expect($employee->first_name)->toBe('TestFirst');
    expect($employee->last_name)->toBe('TestLast');
    expect($employee->date_of_birth)->toBe('1985-03-20');
    expect($employee->currentAddress()?->street)->toBe('Test Street');
    expect($employee->currentAddress()?->house_number)->toBe('123');
    expect($employee->currentAddress()?->postal_code)->toBe('10115');
    expect($employee->currentAddress()?->city)->toBe('Berlin');
    expect($employee->currentAddress()?->country)->toBe('DE');
    expect($employee->hourly_rate)->toBe(18.50);
    expect($employee->tax_id)->toBe('12345678901');
    expect($employee->social_security_number)->toBe('12 345678 A 123');
});

test('employee status check methods return correct boolean', function () {
    $applicant = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => Employee::STATUS_APPLICANT,
    ]);
    expect($applicant->isApplicant())->toBeTrue();
    expect($applicant->isPreContract())->toBeFalse();
    expect($applicant->isActive())->toBeFalse();
    expect($applicant->isOnLeave())->toBeFalse();
    expect($applicant->isTerminated())->toBeFalse();

    $onLeave = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => Employee::STATUS_ON_LEAVE,
    ]);
    expect($onLeave->isOnLeave())->toBeTrue();
    expect($onLeave->isActive())->toBeFalse();
    expect($onLeave->canTerminate())->toBeTrue();
});

test('employee can terminate returns true for active and on leave', function () {
    $active = Employee::factory()->active()->create(['tenant_id' => $this->tenant->id]);
    expect($active->canTerminate())->toBeTrue();

    $onLeave = Employee::factory()->onLeave()->create(['tenant_id' => $this->tenant->id]);
    expect($onLeave->canTerminate())->toBeTrue();

    $preContract = Employee::factory()->preContract()->create(['tenant_id' => $this->tenant->id]);
    expect($preContract->canTerminate())->toBeFalse();

    $terminated = Employee::factory()->terminated()->create(['tenant_id' => $this->tenant->id]);
    expect($terminated->canTerminate())->toBeFalse();
});

test('onboarding invitations are only available for pre-contract employees', function () {
    $applicant = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => Employee::STATUS_APPLICANT,
    ]);
    $preContract = Employee::factory()->preContract()->create(['tenant_id' => $this->tenant->id]);
    $active = Employee::factory()->active()->create(['tenant_id' => $this->tenant->id]);
    $onLeave = Employee::factory()->onLeave()->create(['tenant_id' => $this->tenant->id]);
    $terminated = Employee::factory()->terminated()->create(['tenant_id' => $this->tenant->id]);

    expect($applicant->canReceiveOnboardingInvitation())->toBeFalse()
        ->and($preContract->canReceiveOnboardingInvitation())->toBeTrue()
        ->and($active->canReceiveOnboardingInvitation())->toBeFalse()
        ->and($onLeave->canReceiveOnboardingInvitation())->toBeFalse()
        ->and($terminated->canReceiveOnboardingInvitation())->toBeFalse()
        ->and(Employee::VALID_STATUSES)->toBe([
            Employee::STATUS_APPLICANT,
            Employee::STATUS_PRE_CONTRACT,
            Employee::STATUS_ACTIVE,
            Employee::STATUS_ON_LEAVE,
            Employee::STATUS_TERMINATED,
        ])
        ->and(Employee::INVITABLE_STATUSES)->toBe([
            Employee::STATUS_PRE_CONTRACT,
        ]);
});

test('employee scopes applicants and on leave work correctly', function () {
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => Employee::STATUS_APPLICANT,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => Employee::STATUS_ON_LEAVE,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => Employee::STATUS_ACTIVE,
    ]);

    expect(Employee::applicants()->get())->toHaveCount(1);
    expect(Employee::onLeave()->get())->toHaveCount(1);
});

test('employee scopes with and without user account', function () {
    $user = User::factory()->create();

    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $user->id,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => null,
    ]);

    expect(Employee::withUserAccount()->get())->toHaveCount(1);
    expect(Employee::withoutUserAccount()->get())->toHaveCount(1);
});

test('employee nullable encrypted fields handle null values', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'hourly_rate' => null,
        'tax_id' => null,
        'social_security_number' => null,
    ]);

    $employee->addresses()->delete();

    expect($employee->hourly_rate)->toBeNull();
    expect($employee->tax_id)->toBeNull();
    expect($employee->social_security_number)->toBeNull();
});

test('scopeWithinLevelRange filters only non-management when maxLevel is null', function () {
    // Create employees: 2 non-management, 2 management
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 0,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 0,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 5,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 10,
    ]);

    // maxLevel = NULL should return ONLY non-management employees
    $result = Employee::withinLevelRange(null, null)->get();

    expect($result)->toHaveCount(2);
    expect($result->every(fn ($e) => $e->management_level === 0))->toBeTrue();
});

test('scopeWithinLevelRange filters only non-management when maxLevel is 0', function () {
    // Create employees: 1 non-management, 2 management
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 0,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 3,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 7,
    ]);

    // maxLevel = 0 should return ONLY non-management employees
    $result = Employee::withinLevelRange(null, 0)->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->management_level)->toBe(0);
});

test('scopeWithinLevelRange filters management within range when both min and max provided', function () {
    // Create employees with various management levels
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 0, // Non-management
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 2, // Below range
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 5, // In range
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 8, // In range
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 12, // Above range
    ]);

    // Should return only employees with management_level between 5 and 10
    $result = Employee::withinLevelRange(5, 10)->get();

    expect($result)->toHaveCount(2);
    expect($result->every(fn ($e) => $e->management_level >= 5 && $e->management_level <= 10))->toBeTrue();
});

test('scopeWithinLevelRange filters management up to max when only maxLevel provided', function () {
    // Create employees with various management levels
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 0, // Non-management
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 3,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 7,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 12,
    ]);

    // minLevel = null, maxLevel = 8 → should return management employees with level <= 8
    $result = Employee::withinLevelRange(null, 8)->get();

    expect($result)->toHaveCount(2);
    expect($result->every(fn ($e) => $e->management_level !== 0 && $e->management_level <= 8))->toBeTrue();
});

test('scopeWithinLevelRange filters management from min when minLevel provided with high maxLevel', function () {
    // Create employees with various management levels
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 0, // Non-management
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 1,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 5,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 10,
    ]);
    Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'management_level' => 100,
    ]);

    // minLevel = 5, maxLevel = 255 → should return management employees with level >= 5
    $result = Employee::withinLevelRange(5, 255)->get();

    expect($result)->toHaveCount(3);
    expect($result->every(fn ($e) => $e->management_level !== 0 && $e->management_level >= 5))->toBeTrue();
});
