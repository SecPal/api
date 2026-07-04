<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 */
beforeEach(function () {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('onboarding form template can be created with factory', function () {
    $template = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    expect($template->exists)->toBeTrue()
        ->and($template->tenant_id)->toBe($this->tenant->id)
        ->and($template->name)->toBeString()
        ->and($template->form_schema)->toBeArray()
        ->and($template->is_required)->toBeBool()
        ->and($template->is_system_template)->toBeFalse()
        ->and($template->sort_order)->toBeInt();
});

test('onboarding form template has tenant relationship', function () {
    $template = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    expect($template->tenant)->toBeInstanceOf(TenantKey::class)
        ->and($template->tenant->id)->toBe($this->tenant->id);
});

test('onboarding form template has submissions relationship', function () {
    $template = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $submission = OnboardingFormSubmission::factory()->create([
        'form_template_id' => $template->id,
    ]);

    expect($template->submissions)->toHaveCount(1)
        ->and($template->submissions->first())->toBeInstanceOf(OnboardingFormSubmission::class)
        ->and($template->submissions->first()->id)->toBe($submission->id);
});

test('system template has null tenant id', function () {
    $template = OnboardingFormTemplate::factory()->systemTemplate()->create();

    expect($template->exists)->toBeTrue()
        ->and($template->tenant_id)->toBeNull()
        ->and($template->is_system_template)->toBeTrue()
        ->and($template->is_required)->toBeTrue();
});

test('onboarding form template casts booleans correctly', function () {
    $template = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_required' => true,
        'is_system_template' => false,
    ]);

    expect($template->is_required)->toBeTrue()
        ->and($template->is_system_template)->toBeFalse();

    $template->is_required = false;
    $template->is_system_template = true;
    $template->save();
    $template->refresh();

    expect($template->is_required)->toBeFalse()
        ->and($template->is_system_template)->toBeTrue();
});

test('onboarding form template factory provides a JSON schema object by default', function () {
    $template = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    expect($template->form_schema)->toBeArray()
        ->and($template->form_schema['type'])->toBe('object')
        ->and($template->form_schema['properties'])->toBeArray()
        ->and(array_keys($template->form_schema['properties']))->toBe(['name', 'email', 'field'])
        ->and($template->form_schema['properties']['name'])->toBe(['type' => 'string'])
        ->and($template->form_schema['additionalProperties'])->toBeTrue();
});

test('onboarding form template preserves a custom form schema array', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'custom_field' => ['type' => 'string'],
        ],
        'required' => ['custom_field'],
        'additionalProperties' => false,
    ];

    $template = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
        'form_schema' => $schema,
    ]);

    expect($template->form_schema)->toBeArray()
        ->toBe($schema)
        ->and($template->form_schema['properties'])->toBeArray()
        ->and($template->form_schema['properties']['custom_field'])->toBe(['type' => 'string'])
        ->and($template->form_schema['required'])->toBe(['custom_field'])
        ->and($template->form_schema['additionalProperties'])->toBeFalse();
});

test('onboarding form template factory states work correctly', function () {
    $required = OnboardingFormTemplate::factory()->required()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $optional = OnboardingFormTemplate::factory()->optional()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    expect($required->is_required)->toBeTrue()
        ->and($optional->is_required)->toBeFalse();
});
