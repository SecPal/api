<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\OnboardingSchemaLocalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Setup TenantKey for Spatie Permission (requires tenant_id)
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant->id);

    // Create permissions first
    Permission::firstOrCreate(['name' => 'onboarding_template.write', 'guard_name' => 'sanctum']);
    Permission::firstOrCreate(['name' => 'onboarding_template.delete', 'guard_name' => 'sanctum']);
    Permission::firstOrCreate(['name' => 'onboarding.read', 'guard_name' => 'sanctum']);

    $this->hrUser = User::factory()->create();
    $this->hrUser->givePermissionTo(['onboarding_template.write', 'onboarding_template.delete', 'onboarding.read']);
});

afterEach(function () {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
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

describe('OnboardingFormTemplatePolicy - Direct Policy Methods', function () {
    it('update() returns false for system templates', function () {
        $policy = new \App\Policies\OnboardingFormTemplatePolicy();
        $systemTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => true,
            'name' => 'Personal Information Form',
        ]);

        expect($policy->update($this->hrUser, $systemTemplate))->toBeFalse();
    });

    it('update() returns true for custom templates', function () {
        $policy = new \App\Policies\OnboardingFormTemplatePolicy();
        $customTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => false,
            'name' => 'Custom Template',
        ]);

        expect($policy->update($this->hrUser, $customTemplate))->toBeTrue();
    });

    it('delete() returns false for system templates', function () {
        $policy = new \App\Policies\OnboardingFormTemplatePolicy();
        $systemTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => true,
            'name' => 'Personal Information Form',
        ]);

        expect($policy->delete($this->hrUser, $systemTemplate))->toBeFalse();
    });

    it('delete() returns true for custom templates', function () {
        $policy = new \App\Policies\OnboardingFormTemplatePolicy();
        $customTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => false,
            'name' => 'Custom Template',
        ]);

        expect($policy->delete($this->hrUser, $customTemplate))->toBeTrue();
    });

    it('create() returns true for HR user with template write permission', function () {
        $policy = new \App\Policies\OnboardingFormTemplatePolicy();

        expect($policy->create($this->hrUser))->toBeTrue();
    });

    it('view() returns true for HR user with onboarding read permission', function () {
        $policy = new \App\Policies\OnboardingFormTemplatePolicy();
        $template = OnboardingFormTemplate::factory()->create();

        expect($policy->view($this->hrUser, $template))->toBeTrue();
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
    it('uses prelocalized template payloads without resolving the localization service', function () {
        $template = OnboardingFormTemplate::factory()->create([
            'name' => 'Personal Information Form',
            'description' => 'Original description',
            'form_schema' => ['title' => 'Original title'],
        ]);

        $template->setAttribute(OnboardingFormTemplate::LOCALIZED_TEMPLATE_ATTRIBUTE, [
            'name' => 'Persönliche Informationen',
            'description' => 'Vorlokalisierte Beschreibung',
            'form_schema' => ['title' => 'Vorlokalisierter Titel'],
        ]);

        app()->bind(OnboardingSchemaLocalizationService::class, static function (): never {
            throw new \LogicException('The resource must not resolve the localization service.');
        });

        try {
            $resource = app(App\Http\Resources\OnboardingFormTemplateResource::class, ['resource' => $template]);
            $response = $resource->toArray(request());

            expect($response['name'])->toBe('Persönliche Informationen')
                ->and($response['description'])->toBe('Vorlokalisierte Beschreibung')
                ->and($response['form_schema']['title'])->toBe('Vorlokalisierter Titel');
        } finally {
            app()->forgetInstance(OnboardingSchemaLocalizationService::class);
            app()->offsetUnset(OnboardingSchemaLocalizationService::class);
        }
    });

    it('includes can_be_deleted and can_be_edited flags for system templates', function () {
        $systemTemplate = OnboardingFormTemplate::factory()->create([
            'is_system_template' => true,
            'name' => 'Personal Information Form',
        ]);

        $resource = app(App\Http\Resources\OnboardingFormTemplateResource::class, ['resource' => $systemTemplate]);
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

        $resource = app(App\Http\Resources\OnboardingFormTemplateResource::class, ['resource' => $customTemplate]);
        $response = $resource->toArray(request());

        expect($response)->toHaveKey('can_be_deleted');
        expect($response)->toHaveKey('can_be_edited');
        expect($response['can_be_deleted'])->toBeTrue();
        expect($response['can_be_edited'])->toBeTrue();
        expect($response['is_system_template'])->toBeFalse();
    });
});
