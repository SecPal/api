<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Services\OnboardingCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(OnboardingCompletionService::class);
});

describe('OnboardingCompletionService::checkCompletion', function () {
    it('returns false when employee has no submissions', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
        ]);

        // Create 2 required templates
        OnboardingFormTemplate::factory()->count(2)->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $result = $this->service->checkCompletion($employee);

        expect($result)->toBeFalse();
        expect($employee->fresh()->onboarding_completed)->toBeFalse();
        expect($employee->fresh()->onboarding_completed_at)->toBeNull();
    });

    it('returns false when employee has partial submissions', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
        ]);

        $template1 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $template2 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        // Only submit template1
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template1->id,
            'status' => 'approved',
        ]);

        $result = $this->service->checkCompletion($employee);

        expect($result)->toBeFalse();
        expect($employee->fresh()->onboarding_completed)->toBeFalse();
    });

    it('returns false when submissions are pending (not approved)', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
        ]);

        $template1 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $template2 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        // Both submitted but NOT approved
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template1->id,
            'status' => 'submitted', // Pending approval
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template2->id,
            'status' => 'submitted', // Pending approval
        ]);

        $result = $this->service->checkCompletion($employee);

        expect($result)->toBeFalse();
        expect($employee->fresh()->onboarding_completed)->toBeFalse();
    });

    it('returns true and updates employee when all required templates approved', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
            'onboarding_completed_at' => null,
        ]);

        $template1 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $template2 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        // Both approved
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template1->id,
            'status' => 'approved',
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template2->id,
            'status' => 'approved',
        ]);

        $result = $this->service->checkCompletion($employee);

        expect($result)->toBeTrue();
        expect($employee->fresh()->onboarding_completed)->toBeTrue();
        expect($employee->fresh()->onboarding_completed_at)->not->toBeNull();
    });

    it('ignores optional templates when checking completion', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
        ]);

        $requiredTemplate = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $optionalTemplate = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => false, // Optional
            'is_system_template' => true,
        ]);

        // Only required template approved (optional NOT submitted)
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $requiredTemplate->id,
            'status' => 'approved',
        ]);

        $result = $this->service->checkCompletion($employee);

        // Should be complete despite optional template missing
        expect($result)->toBeTrue();
        expect($employee->fresh()->onboarding_completed)->toBeTrue();
    });

    it('returns true immediately when no required templates exist', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
        ]);

        // Only optional templates
        OnboardingFormTemplate::factory()->count(2)->create([
            'tenant_id' => null,
            'is_required' => false,
            'is_system_template' => true,
        ]);

        $result = $this->service->checkCompletion($employee);

        expect($result)->toBeTrue();
        expect($employee->fresh()->onboarding_completed)->toBeTrue();
    });

    it('does not update employee record if already completed', function () {
        $completedAt = now()->subDays(5);

        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => true,
            'onboarding_completed_at' => $completedAt,
        ]);

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template->id,
            'status' => 'approved',
        ]);

        $result = $this->service->checkCompletion($employee);

        expect($result)->toBeTrue();
        // Timestamp should NOT change
        expect($employee->fresh()->onboarding_completed_at->timestamp)
            ->toBe($completedAt->timestamp);
    });

    it('logs activity when completion is achieved', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
        ]);

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template->id,
            'status' => 'approved',
        ]);

        $this->service->checkCompletion($employee);

        // Check activity log
        assertDatabaseHas('activity_log', [
            'subject_type' => Employee::class,
            'subject_id' => $employee->id,
            'causer_type' => Employee::class,
            'causer_id' => $employee->id,
            'event' => 'onboarding_completed',
        ]);
    });

    it('handles rejected submissions correctly (they do not count)', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
        ]);

        $template1 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $template2 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        // Template1 approved, Template2 rejected
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template1->id,
            'status' => 'approved',
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template2->id,
            'status' => 'rejected', // Rejected submissions don't count
        ]);

        $result = $this->service->checkCompletion($employee);

        expect($result)->toBeFalse();
        expect($employee->fresh()->onboarding_completed)->toBeFalse();
    });
});

describe('OnboardingCompletionService::getCompletionStatus', function () {
    it('returns accurate status with no submissions', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
        ]);

        $template1 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'name' => 'Personal Information',
        ]);

        $template2 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'name' => 'Bank Account',
        ]);

        $status = $this->service->getCompletionStatus($employee);

        expect($status)->toMatchArray([
            'is_completed' => false,
            'total_required' => 2,
            'completed_required' => 0,
        ]);

        expect($status['missing_templates'])->toHaveCount(2);
        $names = collect($status['missing_templates'])->pluck('name')->toArray();
        expect($names)->toContain('Personal Information');
        expect($names)->toContain('Bank Account');
    });

    it('returns accurate status with partial completion', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $template1 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'name' => 'Personal Information',
        ]);

        $template2 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'name' => 'Bank Account',
        ]);

        // Only template1 approved
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template1->id,
            'status' => 'approved',
        ]);

        $status = $this->service->getCompletionStatus($employee);

        expect($status)->toMatchArray([
            'is_completed' => false,
            'total_required' => 2,
            'completed_required' => 1,
        ]);

        expect($status['missing_templates'])->toHaveCount(1);
        expect($status['missing_templates'][0]['name'])->toBe('Bank Account');
    });

    it('returns accurate status with full completion', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $template1 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $template2 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        // Both approved
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template1->id,
            'status' => 'approved',
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template2->id,
            'status' => 'approved',
        ]);

        $status = $this->service->getCompletionStatus($employee);

        expect($status)->toMatchArray([
            'is_completed' => true,
            'total_required' => 2,
            'completed_required' => 2,
        ]);

        expect($status['missing_templates'])->toBeEmpty();
    });

    it('returns templates ordered by sort_order', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'name' => 'Template C',
            'sort_order' => 3,
        ]);

        OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'name' => 'Template A',
            'sort_order' => 1,
        ]);

        OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'name' => 'Template B',
            'sort_order' => 2,
        ]);

        $status = $this->service->getCompletionStatus($employee);

        expect($status['missing_templates'][0]['name'])->toBe('Template A');
        expect($status['missing_templates'][1]['name'])->toBe('Template B');
        expect($status['missing_templates'][2]['name'])->toBe('Template C');
    });

    it('ignores optional templates in completion calculation', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $requiredTemplate = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        OnboardingFormTemplate::factory()->count(3)->create([
            'tenant_id' => null,
            'is_required' => false, // Optional
            'is_system_template' => true,
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $requiredTemplate->id,
            'status' => 'approved',
        ]);

        $status = $this->service->getCompletionStatus($employee);

        expect($status)->toMatchArray([
            'is_completed' => true,
            'total_required' => 1,
            'completed_required' => 1,
            'missing_templates' => [],
        ]);
    });

    it('handles no required templates (instant completion)', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        // Only optional templates
        OnboardingFormTemplate::factory()->count(2)->create([
            'tenant_id' => null,
            'is_required' => false,
            'is_system_template' => true,
        ]);

        $status = $this->service->getCompletionStatus($employee);

        expect($status)->toMatchArray([
            'is_completed' => true,
            'total_required' => 0,
            'completed_required' => 0,
            'missing_templates' => [],
        ]);
    });

    it('does not modify employee record', function () {
        $employee = Employee::factory()->preContract()->create([
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
            'onboarding_completed_at' => null,
        ]);

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $employee->id,
            'form_template_id' => $template->id,
            'status' => 'approved',
        ]);

        // Call getCompletionStatus (should NOT modify employee)
        $status = $this->service->getCompletionStatus($employee);

        expect($status['is_completed'])->toBeTrue();
        // Employee record should NOT be updated
        expect($employee->fresh()->onboarding_completed)->toBeFalse();
        expect($employee->fresh()->onboarding_completed_at)->toBeNull();
    });
});
