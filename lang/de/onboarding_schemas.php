<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'templates' => [
        'personal_information_form' => [
            'name' => 'Persoenliche Informationen',
            'description' => 'BewachV Paragraf 16 erforderliche Informationen fuer das Bewacherregister',
            'schema' => [
                'title' => 'Persoenliche Informationen',
                'description' => 'BewachV Paragraf 16 erforderliche Informationen',
            ],
            'fields' => [
                'gender' => [
                    'title' => 'Geschlecht',
                    'enum' => [
                        'male' => 'Maennlich',
                        'female' => 'Weiblich',
                        'diverse' => 'Divers',
                    ],
                ],
                'birth_name' => ['title' => 'Geburtsname'],
                'previous_names' => ['title' => 'Fruehere Namen'],
                'nationalities' => ['title' => 'Staatsangehoerigkeiten'],
                'intended_activities' => [
                    'title' => 'Beabsichtigte Taetigkeiten (Paragraf 34a GewO)',
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
            'description' => 'Kontodaten fuer die Gehaltszahlung',
            'schema' => [
                'title' => 'Bankverbindung',
                'description' => 'Fuer die Gehaltszahlung',
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
            'description' => 'Ansprechpartner fuer Notfaelle',
            'schema' => [
                'title' => 'Notfallkontakt',
                'description' => 'Ansprechpartner fuer Notfaelle',
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
            'description' => 'Steuer-ID und Steuerklasseninformationen (Paragraf 39e EStG)',
            'schema' => [
                'title' => 'Steueridentifikationsnummer',
                'description' => 'Steuer-ID und Steuerklasseninformationen (Paragraf 39e EStG)',
            ],
            'fields' => [
                'tax_id' => ['title' => 'Steueridentifikationsnummer'],
                'tax_class' => ['title' => 'Steuerklasse'],
                'children_count' => ['title' => 'Anzahl Kinder'],
            ],
        ],
    ],
];
