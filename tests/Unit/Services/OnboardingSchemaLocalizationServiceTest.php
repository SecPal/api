<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OnboardingFormTemplate;
use App\Services\OnboardingSchemaLocalizationService;

test('it localizes schema labels descriptions and enum names for known system templates', function (): void {
    $template = OnboardingFormTemplate::factory()->make([
        'tenant_id' => null,
        'is_system_template' => true,
        'name' => 'Emergency Contact',
        'description' => 'Emergency contact persons',
        'form_schema' => [
            'title' => 'Emergency Contact',
            'description' => 'Emergency contact persons',
            'type' => 'object',
            'properties' => [
                'contact_1_relationship' => [
                    'type' => 'string',
                    'title' => 'Contact 1: Relationship',
                    'enum' => ['spouse', 'partner', 'parent', 'sibling', 'child', 'friend', 'other'],
                ],
            ],
        ],
    ]);

    $localized = app(OnboardingSchemaLocalizationService::class)->localizeTemplate($template, 'de');

    expect($localized['name'])->toBe('Notfallkontakt')
        ->and($localized['description'])->toBe('Ansprechpartner für Notfälle')
        ->and($localized['form_schema']['title'])->toBe('Notfallkontakt')
        ->and($localized['form_schema']['properties']['contact_1_relationship']['title'])->toBe('Kontakt 1: Beziehung')
        ->and($localized['form_schema']['properties']['contact_1_relationship']['enumNames'])->toBe([
            'Ehepartner',
            'Partner',
            'Elternteil',
            'Geschwister',
            'Kind',
            'Freund',
            'Sonstiges',
        ]);
});

test('it falls back to stored values when no translation exists', function (): void {
    $template = OnboardingFormTemplate::factory()->make([
        'tenant_id' => 1,
        'is_system_template' => false,
        'name' => 'Tenant Custom Form',
        'description' => 'Tenant-owned custom description',
        'form_schema' => [
            'title' => 'Tenant Custom Form',
            'description' => 'Tenant-owned custom description',
            'type' => 'object',
            'properties' => [
                'custom_field' => [
                    'type' => 'string',
                    'title' => 'Custom Field',
                ],
            ],
        ],
    ]);

    $localized = app(OnboardingSchemaLocalizationService::class)->localizeTemplate($template, 'de');

    expect($localized['name'])->toBe('Tenant Custom Form')
        ->and($localized['description'])->toBe('Tenant-owned custom description')
        ->and($localized['form_schema']['title'])->toBe('Tenant Custom Form')
        ->and($localized['form_schema']['properties']['custom_field']['title'])->toBe('Custom Field');
});

test('it uses template_key for translation lookup when name has changed', function (): void {
    $template = OnboardingFormTemplate::factory()->make([
        'tenant_id' => null,
        'is_system_template' => true,
        'name' => 'Renamed Emergency Contact Form',
        'template_key' => 'emergency_contact',
        'description' => 'Emergency contact persons',
        'form_schema' => [
            'title' => 'Emergency Contact',
            'description' => 'Emergency contact persons',
            'type' => 'object',
            'properties' => [
                'contact_1_relationship' => [
                    'type' => 'string',
                    'title' => 'Contact 1: Relationship',
                    'enum' => ['spouse', 'partner', 'parent', 'sibling', 'child', 'friend', 'other'],
                ],
            ],
        ],
    ]);

    $localized = app(OnboardingSchemaLocalizationService::class)->localizeTemplate($template, 'de');

    expect($localized['name'])->toBe('Notfallkontakt')
        ->and($localized['description'])->toBe('Ansprechpartner für Notfälle')
        ->and($localized['form_schema']['title'])->toBe('Notfallkontakt');
});

test('it falls back to snake-case name when template_key is null', function (): void {
    $template = OnboardingFormTemplate::factory()->make([
        'tenant_id' => null,
        'is_system_template' => true,
        'name' => 'Emergency Contact',
        'template_key' => null,
        'description' => 'Emergency contact persons',
        'form_schema' => [
            'title' => 'Emergency Contact',
            'type' => 'object',
            'properties' => [],
        ],
    ]);

    $localized = app(OnboardingSchemaLocalizationService::class)->localizeTemplate($template, 'de');

    expect($localized['name'])->toBe('Notfallkontakt')
        ->and($localized['description'])->toBe('Ansprechpartner für Notfälle');
});
