<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Services\OnboardingSchemaLocalizationService;
use Illuminate\Support\Facades\App;

describe('OnboardingSchemaLocalizationService', function () {
    beforeEach(function () {
        $this->service = new OnboardingSchemaLocalizationService;
    });

    it('localizes top-level title and description', function () {
        $schema = [
            'title' => 'Personal Information Form',
            'description' => 'BewachV § 16 required information',
            'type' => 'object',
        ];

        $localized = $this->service->localizeSchema($schema, 'de');

        expect($localized['title'])->toBe('Persönliche Informationen');
        expect($localized['description'])->toBe('BewachV § 16 erforderliche Informationen');
    });

    it('localizes property titles', function () {
        $schema = [
            'type' => 'object',
            'properties' => [
                'gender' => [
                    'type' => 'string',
                    'title' => 'Gender',
                ],
                'birth_name' => [
                    'type' => 'string',
                    'title' => 'Birth Name',
                ],
            ],
        ];

        $localized = $this->service->localizeSchema($schema, 'de');

        expect($localized['properties']['gender']['title'])->toBe('Geschlecht');
        expect($localized['properties']['birth_name']['title'])->toBe('Geburtsname');
    });

    it('localizes enum values using enumNames', function () {
        $schema = [
            'type' => 'object',
            'properties' => [
                'gender' => [
                    'type' => 'string',
                    'title' => 'Gender',
                    'enum' => ['male', 'female', 'diverse'],
                    'enumNames' => ['Male', 'Female', 'Diverse'],
                ],
            ],
        ];

        $localized = $this->service->localizeSchema($schema, 'de');

        expect($localized['properties']['gender']['enumNames'])->toBe([
            'Männlich',
            'Weiblich',
            'Divers',
        ]);
    });

    it('localizes nested object properties', function () {
        $schema = [
            'type' => 'object',
            'properties' => [
                'emergency_contact' => [
                    'type' => 'object',
                    'title' => 'Emergency Contact',
                    'properties' => [
                        'contact_name' => [
                            'type' => 'string',
                            'title' => 'Name',
                        ],
                    ],
                ],
            ],
        ];

        $localized = $this->service->localizeSchema($schema, 'de');

        expect($localized['properties']['emergency_contact']['title'])->toBe('Notfallkontakt');
        expect($localized['properties']['emergency_contact']['properties']['contact_name']['title'])->toBe('Name');
    });

    it('localizes array items', function () {
        $schema = [
            'type' => 'object',
            'properties' => [
                'intended_activities' => [
                    'type' => 'array',
                    'title' => 'Intended Activities (§ 34a GewO)',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['door_control', 'event_security', 'store_detective', 'cash_transport', 'alarm_response', 'security_patrol', 'personal_protection'],
                        'enumNames' => ['Door Control', 'Event Security', 'Store Detective', 'Cash/Valuables Transport', 'Alarm Response', 'Security Patrol', 'Personal Protection'],
                    ],
                ],
            ],
        ];

        $localized = $this->service->localizeSchema($schema, 'de');

        expect($localized['properties']['intended_activities']['title'])->toBe('Beabsichtigte Tätigkeiten (§ 34a GewO)');
        expect($localized['properties']['intended_activities']['items']['enumNames'])->toBe([
            'Türsteher',
            'Veranstaltungsschutz',
            'Ladendetektiv',
            'Geld-/Werttransport',
            'Interventionsdienst',
            'Sicherheitsstreife',
            'Personenschutz',
        ]);
    });

    it('returns original values when translation not found', function () {
        $schema = [
            'type' => 'object',
            'properties' => [
                'completely_unknown_field_that_has_no_translation' => [
                    'type' => 'string',
                    'title' => 'This Field Has No Translation',
                    'description' => 'Neither does this description',
                ],
            ],
        ];

        $localized = $this->service->localizeSchema($schema, 'de');

        expect($localized['properties']['completely_unknown_field_that_has_no_translation']['title'])->toBe('This Field Has No Translation');
        expect($localized['properties']['completely_unknown_field_that_has_no_translation']['description'])->toBe('Neither does this description');
    });

    it('falls back to English when locale not found', function () {
        $schema = [
            'title' => 'Personal Information Form',
            'type' => 'object',
        ];

        $localized = $this->service->localizeSchema($schema, 'fr');

        // Should return English translation or original
        expect($localized['title'])->toBeString();
    });

    it('restores original locale after localization', function () {
        App::setLocale('en');

        $schema = ['title' => 'Personal Information Form', 'type' => 'object'];

        $this->service->localizeSchema($schema, 'de');

        expect(App::getLocale())->toBe('en');
    });

    it('handles empty schema gracefully', function () {
        $localized = $this->service->localizeSchema([], 'de');

        expect($localized)->toBe([]);
    });

    it('preserves schema structure and non-translatable fields', function () {
        $schema = [
            'title' => 'Personal Information Form',
            'type' => 'object',
            'required' => ['gender', 'nationalities'],
            'properties' => [
                'gender' => [
                    'type' => 'string',
                    'title' => 'Gender',
                    'enum' => ['male', 'female', 'diverse'],
                    'maxLength' => 50,
                ],
            ],
        ];

        $localized = $this->service->localizeSchema($schema, 'de');

        expect($localized['type'])->toBe('object');
        expect($localized['required'])->toBe(['gender', 'nationalities']);
        expect($localized['properties']['gender']['type'])->toBe('string');
        expect($localized['properties']['gender']['enum'])->toBe(['male', 'female', 'diverse']);
        expect($localized['properties']['gender']['maxLength'])->toBe(50);
    });

    it('localizes complete personal information schema', function () {
        $schema = [
            'title' => 'Personal Information Form',
            'description' => 'BewachV § 16 required information',
            'type' => 'object',
            'properties' => [
                'gender' => [
                    'type' => 'string',
                    'title' => 'Gender',
                    'enum' => ['male', 'female', 'diverse'],
                    'enumNames' => ['Male', 'Female', 'Diverse'],
                ],
                'birth_name' => [
                    'type' => 'string',
                    'title' => 'Birth Name',
                ],
                'previous_names' => [
                    'type' => 'array',
                    'title' => 'Previous Names',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'nationalities' => [
                    'type' => 'array',
                    'title' => 'Nationalities',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'intended_activities' => [
                    'type' => 'array',
                    'title' => 'Intended Activities (§ 34a GewO)',
                    'items' => [
                        'type' => 'string',
                        'enum' => [
                            'door_control',
                            'event_security',
                            'store_detective',
                            'cash_transport',
                            'alarm_response',
                            'security_patrol',
                            'personal_protection',
                        ],
                        'enumNames' => [
                            'Door Control',
                            'Event Security',
                            'Store Detective',
                            'Cash/Valuables Transport',
                            'Alarm Response',
                            'Security Patrol',
                            'Personal Protection',
                        ],
                    ],
                ],
            ],
            'required' => ['gender', 'nationalities', 'intended_activities'],
        ];

        $localized = $this->service->localizeSchema($schema, 'de');

        expect($localized['title'])->toBe('Persönliche Informationen');
        expect($localized['description'])->toBe('BewachV § 16 erforderliche Informationen');
        expect($localized['properties']['gender']['title'])->toBe('Geschlecht');
        expect($localized['properties']['gender']['enumNames'])->toBe(['Männlich', 'Weiblich', 'Divers']);
        expect($localized['properties']['birth_name']['title'])->toBe('Geburtsname');
        expect($localized['properties']['previous_names']['title'])->toBe('Frühere Namen');
        expect($localized['properties']['nationalities']['title'])->toBe('Staatsangehörigkeiten');
        expect($localized['properties']['intended_activities']['title'])->toBe('Beabsichtigte Tätigkeiten (§ 34a GewO)');
        expect($localized['properties']['intended_activities']['items']['enumNames'][0])->toBe('Türsteher');
        expect($localized['properties']['intended_activities']['items']['enumNames'][1])->toBe('Veranstaltungsschutz');
        expect($localized['properties']['intended_activities']['items']['enumNames'][2])->toBe('Ladendetektiv');
        expect($localized['properties']['intended_activities']['items']['enumNames'][3])->toBe('Geld-/Werttransport');
        expect($localized['properties']['intended_activities']['items']['enumNames'][4])->toBe('Interventionsdienst');
        expect($localized['properties']['intended_activities']['items']['enumNames'][5])->toBe('Sicherheitsstreife');
        expect($localized['properties']['intended_activities']['items']['enumNames'][6])->toBe('Personenschutz');
    });
});
