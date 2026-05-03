<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'templates' => [
        'personal_information_form' => [
            'name' => 'Persönliche Informationen',
            'description' => 'BewachV Paragraf 16 erforderliche Informationen für das Bewacherregister',
            'schema' => [
                'title' => 'Persönliche Informationen',
                'description' => 'BewachV Paragraf 16 erforderliche Informationen',
            ],
            'fields' => [
                'gender' => [
                    'title' => 'Geschlecht',
                    'enum' => [
                        'male' => 'Männlich',
                        'female' => 'Weiblich',
                        'diverse' => 'Divers',
                    ],
                ],
                'birth_name' => ['title' => 'Geburtsname'],
                'previous_names' => ['title' => 'Frühere Namen'],
                'nationalities' => ['title' => 'Staatsangehörigkeiten'],
                'intended_activities' => [
                    'title' => 'Beabsichtigte Tätigkeiten (Paragraf 34a GewO)',
                    'enum' => [
                        'door_control' => 'Einlasskontrolle',
                        'event_security' => 'Veranstaltungsschutz',
                        'store_detective' => 'Ladendetektiv',
                        'cash_transport' => 'Geld- und Werttransport',
                        'alarm_response' => 'Alarmverfolgung',
                        'security_patrol' => 'Streifendienst',
                        'personal_protection' => 'Personenschutz',
                    ],
                ],
            ],
        ],
        'bank_account_details' => [
            'name' => 'Bankverbindung',
            'description' => 'Kontodaten für die Gehaltszahlung',
            'schema' => [
                'title' => 'Bankverbindung',
                'description' => 'Für die Gehaltszahlung',
            ],
            'fields' => [
                'iban' => ['title' => 'IBAN'],
                'bic' => ['title' => 'BIC/SWIFT'],
                'bank_name' => ['title' => 'Bankname'],
                'account_holder' => ['title' => 'Kontoinhaber'],
            ],
        ],
        'emergency_contact' => [
            'name' => 'Notfallkontakt',
            'description' => 'Optionale Angaben zu Notfallkontakten',
            'schema' => [
                'title' => 'Notfallkontakt',
                'description' => 'Optionale Angaben zu Notfallkontakten',
            ],
            'fields' => [
                'contact_1_name' => ['title' => 'Kontakt 1: Name'],
                'contact_1_phone' => ['title' => 'Kontakt 1: Telefon'],
                'contact_1_relationship' => [
                    'title' => 'Kontakt 1: Beziehung',
                    'enum' => [
                        'spouse' => 'Ehepartner',
                        'partner' => 'Partner',
                        'parent' => 'Elternteil',
                        'sibling' => 'Geschwister',
                        'child' => 'Kind',
                        'friend' => 'Freund',
                        'other' => 'Sonstiges',
                    ],
                ],
                'contact_2_name' => ['title' => 'Kontakt 2: Name'],
                'contact_2_phone' => ['title' => 'Kontakt 2: Telefon'],
                'contact_2_relationship' => [
                    'title' => 'Kontakt 2: Beziehung',
                    'enum' => [
                        'spouse' => 'Ehepartner',
                        'partner' => 'Partner',
                        'parent' => 'Elternteil',
                        'sibling' => 'Geschwister',
                        'child' => 'Kind',
                        'friend' => 'Freund',
                        'other' => 'Sonstiges',
                    ],
                ],
            ],
        ],
        'tax_identification_number' => [
            'name' => 'Steueridentifikationsnummer',
            'description' => 'Optionale elfstellige Steueridentifikationsnummer (§ 39e EStG)',
            'schema' => [
                'title' => 'Steueridentifikationsnummer',
                'description' => 'Optionale elfstellige Steueridentifikationsnummer (§ 39e EStG)',
            ],
            'fields' => [
                'tax_id' => ['title' => 'Steueridentifikationsnummer'],
                'children_count' => ['title' => 'Anzahl Kinder'],
            ],
        ],
    ],
];
