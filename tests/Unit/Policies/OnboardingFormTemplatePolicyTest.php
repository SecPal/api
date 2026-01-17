<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OnboardingFormTemplate;
use App\Models\User;
use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->hrUser = User::factory()->create();
    $this->hrUser->givePermissionTo(['onboarding_template.write', 'onboarding_template.delete', 'onboarding.read']);
});

describe('OnboardingFormTemplatePolicy - System Template Protection', function () {
    it('prevents HR from updating system templates', function () {
        $systemTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => true,
            'name' => 'Personal Information Form',
        ]);

        actingAs($this->hrUser);

        expect($this->hrUser->can('update', $systemTemplate))->toBeFalse();
    });

    it('allows HR to update custom templates', function () {
        $customTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => false,
            'name' => 'Custom Template',
        ]);

        actingAs($this->hrUser);

        expect($this->hrUser->can('update', $customTemplate))->toBeTrue();
    });

    it('prevents HR from deleting system templates', function () {
        $systemTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => true,
            'name' => 'Personal Information Form',
        ]);

        actingAs($this->hrUser);

        expect($this->hrUser->can('delete', $systemTemplate))->toBeFalse();
    });

    it('allows HR to delete custom templates', function () {
        $customTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => false,
            'name' => 'Custom Template',
        ]);

        actingAs($this->hrUser);

        expect($this->hrUser->can('delete', $customTemplate))->toBeTrue();
    });

    it('allows HR to create new templates', function () {
        actingAs($this->hrUser);

        expect($this->hrUser->can('create', OnboardingFormTemplate::class))->toBeTrue();
    });

    it('allows HR to view templates', function () {
        $template = OnboardingFormTemplate::factory()->create();

        actingAs($this->hrUser);

        expect($this->hrUser->can('view', $template))->toBeTrue();
    });
});

describe('OnboardingFormTemplate Model - Accessors', function () {
    it('system templates have can_be_deleted = false', function () {
        $systemTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => true,
        ]);

        expect($systemTemplate->can_be_deleted)->toBeFalse();
    });

    it('custom templates have can_be_deleted = true', function () {
        $customTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => false,
        ]);

        expect($customTemplate->can_be_deleted)->toBeTrue();
    });

    it('system templates have can_be_edited = false', function () {
        $systemTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => true,
        ]);

        expect($systemTemplate->can_be_edited)->toBeFalse();
    });

    it('custom templates have can_be_edited = true', function () {
        $customTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => false,
        ]);

        expect($customTemplate->can_be_edited)->toBeTrue();
    });
});

describe('OnboardingFormTemplateResource - API Response', function () {
    it('includes can_be_deleted and can_be_edited flags for system templates', function () {
        $systemTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => true,
            'name' => 'Personal Information Form',
        ]);

        $resource = new \App\Http\Resources\OnboardingFormTemplateResource($systemTemplate);
        $response = $resource->toArray(request());

        expect($response)->toHaveKey('can_be_deleted');
        expect($response)->toHaveKey('can_be_edited');
        expect($response['can_be_deleted'])->toBeFalse();
        expect($response['can_be_edited'])->toBeFalse();
        expect($response['is_system_template'])->toBeTrue();
    });

    it('includes can_be_deleted and can_be_edited flags for custom templates', function () {
        $customTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => false,
            'name' => 'Custom Template',
        ]);

        $resource = new \App\Http\Resources\OnboardingFormTemplateResource($customTemplate);
        $response = $resource->toArray(request());

        expect($response)->toHaveKey('can_be_deleted');
        expect($response)->toHaveKey('can_be_edited');
        expect($response['can_be_deleted'])->toBeTrue();
        expect($response['can_be_edited'])->toBeTrue();
        expect($response['is_system_template'])->toBeFalse();
    });
});
