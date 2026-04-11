<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'templates' => [
        'personal_information_form' => [
            'name' => 'Personal Information Form',
            'description' => 'BewachV Paragraf 16 required information for Bewacherregister',
            'schema' => [
                'title' => 'Personal Information',
                'description' => 'BewachV Paragraf 16 required information',
            ],
            'fields' => [
                'gender' => [
                    'title' => 'Gender',
                    'enum' => [
                        'male' => 'Male',
                        'female' => 'Female',
                        'diverse' => 'Diverse',
                    ],
                ],
                'birth_name' => ['title' => 'Birth Name'],
                'previous_names' => ['title' => 'Previous Names'],
                'nationalities' => ['title' => 'Nationalities'],
                'intended_activities' => [
                    'title' => 'Intended Activities (Section 34a GewO)',
                    'enum' => [
                        'door_control' => 'Door control',
                        'event_security' => 'Event security',
                        'store_detective' => 'Store detective',
                        'cash_transport' => 'Cash and valuables transport',
                        'alarm_response' => 'Alarm response',
                        'security_patrol' => 'Security patrol',
                        'personal_protection' => 'Personal protection',
                    ],
                ],
            ],
        ],
        'bank_account_details' => [
            'name' => 'Bank Account Details',
            'description' => 'Account information for salary payment',
            'schema' => [
                'title' => 'Bank Account Details',
                'description' => 'For salary payment',
            ],
            'fields' => [
                'iban' => ['title' => 'IBAN'],
                'bic' => ['title' => 'BIC/SWIFT'],
                'bank_name' => ['title' => 'Bank Name'],
                'account_holder' => ['title' => 'Account Holder'],
            ],
        ],
        'emergency_contact' => [
            'name' => 'Emergency Contact',
            'description' => 'Emergency contact persons',
            'schema' => [
                'title' => 'Emergency Contact',
                'description' => 'Emergency contact persons',
            ],
            'fields' => [
                'contact_1_name' => ['title' => 'Contact 1: Name'],
                'contact_1_phone' => ['title' => 'Contact 1: Phone'],
                'contact_1_relationship' => [
                    'title' => 'Contact 1: Relationship',
                    'enum' => [
                        'spouse' => 'Spouse',
                        'partner' => 'Partner',
                        'parent' => 'Parent',
                        'sibling' => 'Sibling',
                        'child' => 'Child',
                        'friend' => 'Friend',
                        'other' => 'Other',
                    ],
                ],
                'contact_2_name' => ['title' => 'Contact 2: Name'],
                'contact_2_phone' => ['title' => 'Contact 2: Phone'],
                'contact_2_relationship' => [
                    'title' => 'Contact 2: Relationship',
                    'enum' => [
                        'spouse' => 'Spouse',
                        'partner' => 'Partner',
                        'parent' => 'Parent',
                        'sibling' => 'Sibling',
                        'child' => 'Child',
                        'friend' => 'Friend',
                        'other' => 'Other',
                    ],
                ],
            ],
        ],
        'tax_identification_number' => [
            'name' => 'Tax Identification Number',
            'description' => 'Tax ID and tax class information (Section 39e EStG)',
            'schema' => [
                'title' => 'Tax Identification Number',
                'description' => 'Tax ID and tax class information (Section 39e EStG)',
            ],
            'fields' => [
                'tax_id' => ['title' => 'Tax Identification Number'],
                'tax_class' => ['title' => 'Tax Class'],
                'children_count' => ['title' => 'Number of Children'],
            ],
        ],
    ],
];
