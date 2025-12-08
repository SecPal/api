<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit\Models;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OrganizationalUnit;
use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected TenantKey $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable EmployeeObserver for unit tests - we test the model in isolation
        Employee::unsetEventDispatcher();

        // Create KEK and tenant (no factory for TenantKey)
        TenantKey::setKekPath(getTestKekPath());
        TenantKey::generateKek();
        $keys = TenantKey::generateEnvelopeKeys();
        $this->tenant = TenantKey::create($keys);
    }

    protected function tearDown(): void
    {
        cleanupTestKekFile();
        TenantKey::setKekPath(null);
        parent::tearDown();
    }

    public function test_employee_model_encrypts_and_decrypts_personal_data_using_enc_fields(): void
    {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'date_of_birth' => '1990-05-15',
        ]);

        // Check encrypted fields exist in database
        $this->assertNotNull($employee->getAttributeValue('first_name_enc'));
        $this->assertNotNull($employee->getAttributeValue('last_name_enc'));
        $this->assertNotNull($employee->getAttributeValue('date_of_birth_enc'));

        // Check accessors decrypt correctly
        $this->assertEquals('Max', $employee->first_name);
        $this->assertEquals('Mustermann', $employee->last_name);
        $this->assertEquals('1990-05-15', $employee->date_of_birth);
    }

    public function test_employee_encrypts_tax_id_and_social_security_number(): void
    {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'tax_id' => '12345678901',
            'social_security_number' => '65 123456 A 123',
        ]);

        // Check encrypted fields exist in database
        $this->assertNotNull($employee->getAttributeValue('tax_id_enc'));
        $this->assertNotNull($employee->getAttributeValue('social_security_number_enc'));

        // Check accessors decrypt correctly
        $this->assertEquals('12345678901', $employee->tax_id);
        $this->assertEquals('65 123456 A 123', $employee->social_security_number);

        // Ensure encrypted values in DB are JSON (not plaintext)
        $rawTaxId = $employee->getAttributes()['tax_id_enc'];
        $rawSsn = $employee->getAttributes()['social_security_number_enc'];

        $this->assertStringContainsString('"ciphertext"', $rawTaxId);
        $this->assertStringContainsString('"nonce"', $rawTaxId);
        $this->assertStringContainsString('"ciphertext"', $rawSsn);
        $this->assertStringContainsString('"nonce"', $rawSsn);
    }

    public function test_employee_model_generates_blind_indexes_for_searchable_encrypted_fields(): void
    {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Anna',
            'last_name' => 'Schmidt',
        ]);

        // Check blind indexes are generated (base64-encoded SHA256 HMAC = 44 chars)
        $this->assertNotNull($employee->first_name_idx);
        $this->assertNotNull($employee->last_name_idx);
        $this->assertEquals(44, strlen($employee->first_name_idx));
        $this->assertEquals(44, strlen($employee->last_name_idx));
    }

    public function test_employee_date_of_birth_accessor_returns_string_not_carbon_object(): void
    {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'date_of_birth' => '1985-12-25',
        ]);

        $dateOfBirth = $employee->date_of_birth;

        $this->assertIsString($dateOfBirth);
        $this->assertEquals('1985-12-25', $dateOfBirth);
    }

    public function test_employee_full_name_accessor_combines_decrypted_first_and_last_names(): void
    {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->assertEquals('John Doe', $employee->full_name);
    }

    public function test_employee_status_state_machine_methods_work_correctly(): void
    {
        $preContract = Employee::factory()->create(['status' => 'pre_contract']);
        $active = Employee::factory()->create(['status' => 'active']);
        $terminated = Employee::factory()->create(['status' => 'terminated']);

        $this->assertTrue($preContract->isPreContract());
        $this->assertFalse($preContract->isActive());
        $this->assertFalse($preContract->isTerminated());

        $this->assertTrue($active->isActive());
        $this->assertFalse($active->isPreContract());

        $this->assertTrue($terminated->isTerminated());
        $this->assertFalse($terminated->isActive());
    }

    public function test_employee_can_activate_when_onboarding_complete_and_contract_started(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'pre_contract',
            'onboarding_completed' => true,
            'contract_start_date' => now()->subDay(),
        ]);

        $this->assertTrue($employee->canActivate());
    }

    public function test_employee_cannot_activate_when_onboarding_incomplete(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'pre_contract',
            'onboarding_completed' => false,
            'contract_start_date' => now()->subDay(),
        ]);

        $this->assertFalse($employee->canActivate());
    }

    public function test_employee_scopes_filter_correctly(): void
    {
        Employee::factory()->create(['status' => 'pre_contract']);
        Employee::factory()->create(['status' => 'active']);
        Employee::factory()->create(['status' => 'terminated']);
        Employee::factory()->create(['status' => 'on_leave']);

        $this->assertEquals(1, Employee::preContract()->count());
        $this->assertEquals(1, Employee::active()->count());
        $this->assertEquals(1, Employee::terminated()->count());
        $this->assertEquals(1, Employee::onLeave()->count());
    }

    public function test_get_default_onboarding_steps_returns_consistent_structure_with_completed_at_and_form_submission_id(): void
    {
        $steps = Employee::getDefaultOnboardingSteps();

        $this->assertIsArray($steps);
        $this->assertArrayHasKey('steps', $steps);
        $this->assertIsArray($steps['steps']);
        $this->assertGreaterThan(0, count($steps['steps']));

        foreach ($steps['steps'] as $step) {
            $this->assertArrayHasKey('id', $step);
            $this->assertArrayHasKey('name', $step);
            $this->assertArrayHasKey('completed', $step);
            $this->assertArrayHasKey('completed_at', $step);
            $this->assertArrayHasKey('form_submission_id', $step);
            $this->assertFalse($step['completed']);
            $this->assertNull($step['completed_at']);
            $this->assertNull($step['form_submission_id']);
        }
    }

    public function test_employee_relationships_load_correctly(): void
    {
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

        $this->assertInstanceOf(User::class, $employee->user);
        $this->assertInstanceOf(OrganizationalUnit::class, $employee->organizationalUnit);
        $this->assertCount(1, $employee->qualifications);
        $this->assertCount(1, $employee->documents);
    }

    public function test_employee_mutators_trigger_encryption(): void
    {
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
        $this->assertSame('TestFirst', $employee->first_name);
        $this->assertSame('TestLast', $employee->last_name);
        $this->assertSame('1985-03-20', $employee->date_of_birth);
        $this->assertSame('123 Test Street, Berlin', $employee->address);
        $this->assertEquals(18.50, $employee->hourly_rate);
        $this->assertSame('12345678901', $employee->tax_id);
        $this->assertSame('12 345678 A 123', $employee->social_security_number);
    }

    public function test_employee_status_check_methods_return_correct_boolean(): void
    {
        $applicant = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => Employee::STATUS_APPLICANT,
        ]);
        $this->assertTrue($applicant->isApplicant());
        $this->assertFalse($applicant->isPreContract());
        $this->assertFalse($applicant->isActive());
        $this->assertFalse($applicant->isOnLeave());
        $this->assertFalse($applicant->isTerminated());

        $onLeave = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => Employee::STATUS_ON_LEAVE,
        ]);
        $this->assertTrue($onLeave->isOnLeave());
        $this->assertFalse($onLeave->isActive());
        $this->assertTrue($onLeave->canTerminate());
    }

    public function test_employee_can_terminate_returns_true_for_active_and_on_leave(): void
    {
        $active = Employee::factory()->active()->create(['tenant_id' => $this->tenant->id]);
        $this->assertTrue($active->canTerminate());

        $onLeave = Employee::factory()->onLeave()->create(['tenant_id' => $this->tenant->id]);
        $this->assertTrue($onLeave->canTerminate());

        $preContract = Employee::factory()->preContract()->create(['tenant_id' => $this->tenant->id]);
        $this->assertFalse($preContract->canTerminate());

        $terminated = Employee::factory()->terminated()->create(['tenant_id' => $this->tenant->id]);
        $this->assertFalse($terminated->canTerminate());
    }

    public function test_employee_scopes_applicants_and_on_leave_work_correctly(): void
    {
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

        $this->assertCount(1, Employee::applicants()->get());
        $this->assertCount(1, Employee::onLeave()->get());
    }

    public function test_employee_scopes_with_and_without_user_account(): void
    {
        $user = User::factory()->create();

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
        ]);
        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => null,
        ]);

        $this->assertCount(1, Employee::withUserAccount()->get());
        $this->assertCount(1, Employee::withoutUserAccount()->get());
    }

    public function test_employee_nullable_encrypted_fields_handle_null_values(): void
    {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'address' => null,
            'hourly_rate' => null,
            'tax_id' => null,
            'social_security_number' => null,
        ]);

        $this->assertNull($employee->address);
        $this->assertNull($employee->hourly_rate);
        $this->assertNull($employee->tax_id);
        $this->assertNull($employee->social_security_number);
    }
}
