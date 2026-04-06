<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * @property TenantKey $tenant
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('OnboardingFormSubmission Encryption', function () {
    test('form_data is encrypted at rest', function () {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = OnboardingFormTemplate::factory()->create(['tenant_id' => $this->tenant->id]);

        $sensitiveData = [
            'emergency_contact_name' => 'Jane Doe',
            'emergency_contact_phone' => '+49 123 456789',
            'bank_iban' => 'DE89 3704 0044 0532 0130 00',
            'health_insurance_number' => '1234567890',
        ];

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template->id,
            'form_data' => $sensitiveData,
        ]);

        // Read raw encrypted data from database
        $raw = DB::table('onboarding_form_submissions')->where('id', $submission->id)->first();

        // Verify data is encrypted (not plaintext JSON)
        expect($raw->form_data)
            ->toBeString()
            ->not->toContain('Jane Doe')
            ->not->toContain('+49 123')
            ->not->toContain('DE89');

        // Verify accessor decrypts correctly
        expect($submission->form_data)
            ->toBe($sensitiveData)
            ->and($submission->form_data['emergency_contact_name'])->toBe('Jane Doe')
            ->and($submission->form_data['bank_iban'])->toBe('DE89 3704 0044 0532 0130 00');
    });

    test('form_data can be null', function () {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = OnboardingFormTemplate::factory()->create(['tenant_id' => $this->tenant->id]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template->id,
            'form_data' => null,
        ]);

        expect($submission->form_data)->toBeNull();
    });

    test('form_data handles complex nested structures', function () {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = OnboardingFormTemplate::factory()->create(['tenant_id' => $this->tenant->id]);

        $complexData = [
            'personal' => [
                'emergency_contacts' => [
                    ['name' => 'Jane Doe', 'phone' => '+49 123 456789', 'relation' => 'spouse'],
                    ['name' => 'John Smith', 'phone' => '+49 987 654321', 'relation' => 'parent'],
                ],
            ],
            'banking' => [
                'iban' => 'DE89 3704 0044 0532 0130 00',
                'bic' => 'COBADEFFXXX',
                'bank_name' => 'Commerzbank',
            ],
            'health' => [
                'insurance_type' => 'public',
                'insurance_provider' => 'TK',
                'insurance_number' => '1234567890',
            ],
        ];

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template->id,
            'form_data' => $complexData,
        ]);

        // Verify decryption preserves structure
        expect($submission->form_data)
            ->toBe($complexData)
            ->and($submission->form_data['personal']['emergency_contacts'])->toHaveCount(2)
            ->and($submission->form_data['banking']['iban'])->toBe('DE89 3704 0044 0532 0130 00');
    });

    test('updating form_data re-encrypts with new data', function () {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = OnboardingFormTemplate::factory()->create(['tenant_id' => $this->tenant->id]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template->id,
            'form_data' => ['initial' => 'data'],
        ]);

        $rawBefore = DB::table('onboarding_form_submissions')->where('id', $submission->id)->first();

        // Update with new data
        $submission->update([
            'form_data' => ['updated' => 'data', 'new_field' => 'value'],
        ]);

        $rawAfter = DB::table('onboarding_form_submissions')->where('id', $submission->id)->first();

        // Verify encrypted data changed
        expect($rawAfter->form_data)->not->toBe($rawBefore->form_data);

        // Verify decryption works
        expect($submission->fresh()->form_data)
            ->toBe(['updated' => 'data', 'new_field' => 'value'])
            ->and($submission->fresh()->form_data)->not->toHaveKey('initial');
    });
});
