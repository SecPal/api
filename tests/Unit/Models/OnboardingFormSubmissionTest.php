<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @property \App\Models\TenantKey $tenant
 */
beforeEach(function () {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('onboarding form submission can be created with factory', function () {
    $submission = OnboardingFormSubmission::factory()->create();

    expect($submission->exists)->toBeTrue()
        ->and($submission->employee_id)->toBeString()
        ->and($submission->form_template_id)->toBeString()
        ->and($submission->form_data)->toBeArray()
        ->and($submission->status)->toBe('draft')
        ->and($submission->submitted_at)->toBeNull();
});

test('onboarding form submission has employee relationship', function () {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $submission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $employee->id,
    ]);

    expect($submission->employee)->toBeInstanceOf(Employee::class)
        ->and($submission->employee->id)->toBe($employee->id);
});

test('onboarding form submission has form template relationship', function () {
    $template = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $submission = OnboardingFormSubmission::factory()->create([
        'form_template_id' => $template->id,
    ]);

    expect($submission->formTemplate)->toBeInstanceOf(OnboardingFormTemplate::class)
        ->and($submission->formTemplate->id)->toBe($template->id);
});

test('onboarding form submission has reviewer relationship', function () {
    $user = User::factory()->create();
    $submission = OnboardingFormSubmission::factory()->approved()->create([
        'reviewed_by' => $user->id,
    ]);

    expect($submission->reviewer)->toBeInstanceOf(User::class)
        ->and($submission->reviewer->id)->toBe($user->id);
});

test('onboarding form submission casts dates correctly', function () {
    $submission = OnboardingFormSubmission::factory()->create([
        'submitted_at' => now(),
    ]);

    expect($submission->submitted_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($submission->reviewed_at)->toBeNull();

    $submission->reviewed_at = now();
    $submission->save();
    $submission->refresh();

    expect($submission->reviewed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('onboarding form submission casts form data as array', function () {
    $formData = [
        'question_1' => 'Answer 1',
        'question_2' => 'Answer 2',
    ];

    $submission = OnboardingFormSubmission::factory()->create([
        'form_data' => $formData,
    ]);

    expect($submission->form_data)->toBeArray()
        ->toBe($formData)
        ->and($submission->form_data['question_1'])->toBe('Answer 1');
});

test('onboarding form submission factory states work correctly', function () {
    $submitted = OnboardingFormSubmission::factory()->submitted()->create();
    $approved = OnboardingFormSubmission::factory()->approved()->create();
    $rejected = OnboardingFormSubmission::factory()->rejected()->create();

    expect($submitted->status)->toBe('submitted')
        ->and($submitted->submitted_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($submitted->reviewed_by)->toBeNull()
        ->and($submitted->reviewed_at)->toBeNull();

    expect($approved->status)->toBe('approved')
        ->and($approved->reviewed_by)->not->toBeNull()
        ->and($approved->reviewed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->reviewed_by)->not->toBeNull()
        ->and($rejected->reviewed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});
