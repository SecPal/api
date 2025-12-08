<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit\Models;

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingFormSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected TenantKey $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Create KEK and tenant (no factory for TenantKey)
        TenantKey::setKekPath(getTestKekPath());
        TenantKey::generateKek();
        $keys = TenantKey::generateEnvelopeKeys();
        $this->tenant = TenantKey::create($keys);
    }

    public function test_onboarding_form_submission_can_be_created_with_factory(): void
    {
        $submission = OnboardingFormSubmission::factory()->create();

        $this->assertTrue($submission->exists);
        $this->assertIsString($submission->employee_id);
        $this->assertIsString($submission->form_template_id);
        $this->assertIsArray($submission->form_data);
        $this->assertSame('draft', $submission->status);
        $this->assertNull($submission->submitted_at);
    }

    public function test_onboarding_form_submission_has_employee_relationship(): void
    {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
        ]);

        $this->assertInstanceOf(Employee::class, $submission->employee);
        $this->assertSame($employee->id, $submission->employee->id);
    }

    public function test_onboarding_form_submission_has_form_template_relationship(): void
    {
        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $submission = OnboardingFormSubmission::factory()->create([
            'form_template_id' => $template->id,
        ]);

        $this->assertInstanceOf(OnboardingFormTemplate::class, $submission->formTemplate);
        $this->assertSame($template->id, $submission->formTemplate->id);
    }

    public function test_onboarding_form_submission_has_reviewer_relationship(): void
    {
        $user = User::factory()->create();
        $submission = OnboardingFormSubmission::factory()->approved()->create([
            'reviewed_by' => $user->id,
        ]);

        $this->assertInstanceOf(User::class, $submission->reviewer);
        $this->assertSame($user->id, $submission->reviewer->id);
    }

    public function test_onboarding_form_submission_casts_dates_correctly(): void
    {
        $submission = OnboardingFormSubmission::factory()->create([
            'submitted_at' => now(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $submission->submitted_at);
        $this->assertNull($submission->reviewed_at);

        $submission->reviewed_at = now();
        $submission->save();
        $submission->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $submission->reviewed_at);
    }

    public function test_onboarding_form_submission_casts_form_data_as_array(): void
    {
        $formData = [
            'question_1' => 'Answer 1',
            'question_2' => 'Answer 2',
        ];

        $submission = OnboardingFormSubmission::factory()->create([
            'form_data' => $formData,
        ]);

        $this->assertIsArray($submission->form_data);
        $this->assertSame($formData, $submission->form_data);
        $this->assertSame('Answer 1', $submission->form_data['question_1']);
    }

    public function test_onboarding_form_submission_factory_states_work_correctly(): void
    {
        $submitted = OnboardingFormSubmission::factory()->submitted()->create();
        $approved = OnboardingFormSubmission::factory()->approved()->create();
        $rejected = OnboardingFormSubmission::factory()->rejected()->create();

        $this->assertSame('submitted', $submitted->status);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $submitted->submitted_at);
        $this->assertNull($submitted->reviewed_by);
        $this->assertNull($submitted->reviewed_at);

        $this->assertSame('approved', $approved->status);
        $this->assertNotNull($approved->reviewed_by);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $approved->reviewed_at);

        $this->assertSame('rejected', $rejected->status);
        $this->assertNotNull($rejected->reviewed_by);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $rejected->reviewed_at);
    }
}

