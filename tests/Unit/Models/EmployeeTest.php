<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OrganizationalUnit;
use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('unit', 'models', 'employee');

beforeEach(function () {
    // Disable EmployeeObserver for unit tests - we test the model in isolation
    Employee::unsetEventDispatcher();

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
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'date_of_birth' => '1990-05-15',
    ]);

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
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'tax_id' => '12345678901',
        'social_security_number' => '65 123456 A 123',
    ]);

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

test('employee model generates blind indexes for searchable encrypted fields', function () {
    // Create employee without observer, then manually trigger blind index generation
    $employee = Employee::factory()->make([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Anna',
        'last_name' => 'Schmidt',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    // Manually generate blind indexes by calling the observer's method
    $observer = new \App\Observers\EmployeeObserver;
    $observer->creating($employee);

    // Save the employee
    $employee->save();

    // Check blind indexes are generated (base64-encoded SHA256 HMAC = 44 chars)
    expect($employee->first_name_idx)->not->toBeNull();
    expect($employee->last_name_idx)->not->toBeNull();
    expect(strlen($employee->first_name_idx))->toBe(44);
    expect(strlen($employee->last_name_idx))->toBe(44);
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

test('employee can activate when onboarding complete and contract started', function () {
    $employee = Employee::factory()->create([
        'status' => 'pre_contract',
        'onboarding_completed' => true,
        'contract_start_date' => now()->subDay(),
    ]);

    expect($employee->canActivate())->toBeTrue();
});

test('employee cannot activate when onboarding incomplete', function () {
    $employee = Employee::factory()->create([
        'status' => 'pre_contract',
        'onboarding_completed' => false,
        'contract_start_date' => now()->subDay(),
    ]);

    expect($employee->canActivate())->toBeFalse();
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
    expect(count($steps['steps']))->toBeGreaterThan(0);

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
    $orgUnit = OrganizationalUnit::factory()->create();
    $user = User::factory()->create();

    $employee = Employee::factory()->create([
        'organizational_unit_id' => $orgUnit->id,
        'user_id' => $user->id,
    ]);

    $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);
    $employee->qualifications()->attach($qualification->id, [
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'obtained_date' => now(),
    ]);

    EmployeeDocument::factory()->create(['employee_id' => $employee->id]);

    $employee->load(['user', 'organizationalUnit', 'qualifications', 'documents']);

    expect($employee->user)->toBeInstanceOf(User::class);
    expect($employee->organizationalUnit)->toBeInstanceOf(OrganizationalUnit::class);
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
    $employee->address = '123 Test Street, Berlin';
    $employee->hourly_rate = 18.50;
    $employee->tax_id = '12345678901';
    $employee->social_security_number = '12 345678 A 123';

    $employee->save();
    $employee->refresh();

    // Verify values are encrypted and decrypted correctly
    expect($employee->first_name)->toBe('TestFirst');
    expect($employee->last_name)->toBe('TestLast');
    expect($employee->date_of_birth)->toBe('1985-03-20');
    expect($employee->address)->toBe('123 Test Street, Berlin');
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
        'address' => null,
        'hourly_rate' => null,
        'tax_id' => null,
        'social_security_number' => null,
    ]);

    expect($employee->address)->toBeNull();
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
