<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OnboardingFormTemplate;
use Database\Seeders\OnboardingFormTemplatesSeeder;

use function Pest\Laravel\artisan;

beforeEach(function () {
    // Clean database before each test
    OnboardingFormTemplate::query()->forceDelete();
});

test('seeder creates exactly 4 standard templates', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    expect(OnboardingFormTemplate::count())->toBe(4);
});

test('all templates are system templates', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $systemTemplates = OnboardingFormTemplate::where('is_system_template', true)->count();
    expect($systemTemplates)->toBe(4);
});

test('all templates have null tenant_id (system-wide)', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $systemWideTemplates = OnboardingFormTemplate::whereNull('tenant_id')->count();
    expect($systemWideTemplates)->toBe(4);
});

test('personal information form is required', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $template = OnboardingFormTemplate::where('name', 'Personal Information Form')->first();

    expect($template)->not->toBeNull();
    expect($template->is_required)->toBeTrue();
});

test('other templates are not required', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $optionalTemplates = OnboardingFormTemplate::where('is_required', false)->count();
    expect($optionalTemplates)->toBe(3); // Bank, Emergency, Tax
});

test('templates have correct sort order', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $templates = OnboardingFormTemplate::orderBy('sort_order')->get();

    expect($templates)->toHaveCount(4);
    expect($templates[0]->name)->toBe('Personal Information Form');
    expect($templates[0]->sort_order)->toBe(1);
    expect($templates[1]->name)->toBe('Bank Account Details');
    expect($templates[1]->sort_order)->toBe(2);
    expect($templates[2]->name)->toBe('Emergency Contact');
    expect($templates[2]->sort_order)->toBe(3);
    expect($templates[3]->name)->toBe('Tax Identification Number');
    expect($templates[3]->sort_order)->toBe(4);
});

test('personal information schema has correct structure', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $template = OnboardingFormTemplate::where('name', 'Personal Information Form')->first();
    $schema = $template->form_schema;

    expect($schema)->toBeArray();
    expect($schema)->toHaveKey('title');
    expect($schema)->toHaveKey('type');
    expect($schema)->toHaveKey('properties');
    expect($schema)->toHaveKey('required');

    // Check required fields
    expect($schema['properties'])->toHaveKey('gender');
    expect($schema['properties'])->toHaveKey('nationalities');
    expect($schema['properties'])->toHaveKey('intended_activities');

    // Check optional fields
    expect($schema['properties'])->toHaveKey('birth_name');
    expect($schema['properties'])->toHaveKey('previous_names');

    // Verify required array
    expect($schema['required'])->toContain('gender');
    expect($schema['required'])->toContain('nationalities');
    expect($schema['required'])->toContain('intended_activities');
});

test('personal information schema validates gender enum', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $template = OnboardingFormTemplate::where('name', 'Personal Information Form')->first();
    $schema = $template->form_schema;

    expect($schema['properties']['gender']['enum'])->toBe(['male', 'female', 'diverse']);
});

test('personal information schema validates intended activities enum', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $template = OnboardingFormTemplate::where('name', 'Personal Information Form')->first();
    $schema = $template->form_schema;

    $expectedActivities = [
        'door_control',
        'event_security',
        'store_detective',
        'cash_transport',
        'alarm_response',
        'security_patrol',
        'personal_protection',
    ];

    expect($schema['properties']['intended_activities']['items']['enum'])->toBe($expectedActivities);
});

test('bank account schema has correct structure', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $template = OnboardingFormTemplate::where('name', 'Bank Account Details')->first();
    $schema = $template->form_schema;

    expect($schema)->toBeArray();
    expect($schema['properties'])->toHaveKey('iban');
    expect($schema['properties'])->toHaveKey('bic');
    expect($schema['properties'])->toHaveKey('bank_name');
    expect($schema['properties'])->toHaveKey('account_holder');

    // Check required fields
    expect($schema['required'])->toContain('iban');
    expect($schema['required'])->toContain('account_holder');
    expect($schema['required'])->not->toContain('bic');
    expect($schema['required'])->not->toContain('bank_name');
});

test('bank account schema has IBAN pattern validation', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $template = OnboardingFormTemplate::where('name', 'Bank Account Details')->first();
    $schema = $template->form_schema;

    expect($schema['properties']['iban']['pattern'])->toBe('^[A-Z]{2}\d{2}[A-Z0-9]+$');
    expect($schema['properties']['iban']['maxLength'])->toBe(34);
});

test('emergency contact schema has correct structure', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $template = OnboardingFormTemplate::where('name', 'Emergency Contact')->first();
    $schema = $template->form_schema;

    // Contact 1 fields (required)
    expect($schema['properties'])->toHaveKey('contact_1_name');
    expect($schema['properties'])->toHaveKey('contact_1_phone');
    expect($schema['properties'])->toHaveKey('contact_1_relationship');

    // Contact 2 fields (optional)
    expect($schema['properties'])->toHaveKey('contact_2_name');
    expect($schema['properties'])->toHaveKey('contact_2_phone');
    expect($schema['properties'])->toHaveKey('contact_2_relationship');

    // Only contact 1 is required
    expect($schema['required'])->toContain('contact_1_name');
    expect($schema['required'])->toContain('contact_1_phone');
    expect($schema['required'])->toContain('contact_1_relationship');
    expect($schema['required'])->not->toContain('contact_2_name');
});

test('emergency contact relationship enum includes common relationships', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $template = OnboardingFormTemplate::where('name', 'Emergency Contact')->first();
    $schema = $template->form_schema;

    $expectedRelationships = [
        'spouse',
        'partner',
        'parent',
        'sibling',
        'child',
        'friend',
        'other',
    ];

    expect($schema['properties']['contact_1_relationship']['enum'])->toBe($expectedRelationships);
});

test('tax identification schema has correct structure', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $template = OnboardingFormTemplate::where('name', 'Tax Identification Number')->first();
    $schema = $template->form_schema;

    expect($schema['properties'])->toHaveKey('tax_id');
    expect($schema['properties'])->toHaveKey('tax_class');
    expect($schema['properties'])->toHaveKey('children_count');

    // Check required fields
    expect($schema['required'])->toContain('tax_id');
    expect($schema['required'])->toContain('tax_class');
    expect($schema['required'])->not->toContain('children_count');
});

test('tax identification validates 11-digit tax ID', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $template = OnboardingFormTemplate::where('name', 'Tax Identification Number')->first();
    $schema = $template->form_schema;

    expect($schema['properties']['tax_id']['pattern'])->toBe('^\d{11}$');
});

test('tax class enum includes all 6 tax classes', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $template = OnboardingFormTemplate::where('name', 'Tax Identification Number')->first();
    $schema = $template->form_schema;

    expect($schema['properties']['tax_class']['enum'])->toBe([1, 2, 3, 4, 5, 6]);
});

test('seeder is idempotent (can be run multiple times)', function () {
    // Run seeder twice
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    // Should still have exactly 4 templates
    expect(OnboardingFormTemplate::count())->toBe(4);
});

test('all templates have descriptions', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $templatesWithoutDescription = OnboardingFormTemplate::whereNull('description')->count();
    expect($templatesWithoutDescription)->toBe(0);
});

test('all schemas have title and type properties', function () {
    artisan('db:seed', ['--class' => OnboardingFormTemplatesSeeder::class]);

    $templates = OnboardingFormTemplate::all();

    foreach ($templates as $template) {
        $schema = $template->form_schema;
        expect($schema)->toHaveKey('title');
        expect($schema)->toHaveKey('type');
        expect($schema['type'])->toBe('object');
    }
});
