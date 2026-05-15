<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'templates' => [
        'personal_information_form' => [
            'name' => 'Personal Information Form',
            'description' => 'Your personal details for onboarding; HR may complete further Bewacherregister fields later.',
            'schema' => [
                'title' => 'Personal Information',
                'description' => 'Information required for onboarding; planned activities under Section 34a GewO can be completed later by HR for Bewacherregister export.',
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
                'intended_activities' => [
                    'title' => 'Intended Activities (Section 34a GewO)',
                    'description' => 'Optional during onboarding; HR must confirm or enter these before Bewacherregister export if you skip them.',
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
        'nationality_and_residence' => [
            'name' => 'Nationality and Residence',
            'description' => 'Nationality, residence title, and employment authorization status.',
            'schema' => [
                'title' => 'Nationality and Residence',
                'description' => 'Nationality, residence title, and employment authorization status.',
            ],
            'fields' => [
                'nationalities' => ['title' => 'Nationalities'],
                'residence_permit_title' => ['title' => 'Residence Title'],
                'residence_permit_employment_allowed' => [
                    'title' => 'Employment Authorization',
                    'enum' => [
                        'yes' => 'Yes',
                        'no' => 'No',
                    ],
                ],
                'residence_permit_unlimited' => ['title' => 'Residence Title Is Unlimited'],
                'residence_permit_expiry' => ['title' => 'Residence Title Expiry Date'],
            ],
        ],
        'residential_address_history' => [
            'name' => 'Residential Address History',
            'description' => 'Current residential address and previous residences from the last five years.',
            'schema' => [
                'title' => 'Residential Address History',
                'description' => 'Provide your current residential address, the date since you have lived there, and earlier residences covering the last five years.',
            ],
            'fields' => [
                'current_address' => [
                    'title' => 'Current Residential Address',
                    'street' => ['title' => 'Street'],
                    'house_number' => ['title' => 'House Number'],
                    'postal_code' => ['title' => 'Postal Code'],
                    'city' => ['title' => 'City'],
                    'supplement' => ['title' => 'Address Supplement'],
                    'country' => ['title' => 'Country'],
                    'resided_from' => ['title' => 'Living There Since'],
                ],
                'previous_addresses' => [
                    'title' => 'Previous Residences',
                    'street' => ['title' => 'Street'],
                    'house_number' => ['title' => 'House Number'],
                    'postal_code' => ['title' => 'Postal Code'],
                    'city' => ['title' => 'City'],
                    'supplement' => ['title' => 'Address Supplement'],
                    'country' => ['title' => 'Country'],
                    'resided_from' => ['title' => 'Resided From'],
                    'resided_until' => ['title' => 'Resided Until'],
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
            'description' => 'Optional emergency contact persons',
            'schema' => [
                'title' => 'Emergency Contact',
                'description' => 'Optional emergency contact persons',
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
            'description' => 'Required eleven-digit tax identification number (Section 39e EStG) and social security number.',
            'schema' => [
                'title' => 'Tax Identification Number',
                'description' => 'Required eleven-digit tax identification number (Section 39e EStG) and social security number.',
            ],
            'fields' => [
                'tax_id' => ['title' => 'Tax Identification Number'],
                'social_security_number' => ['title' => 'Social Security Number'],
                'children_count' => ['title' => 'Number of Children'],
            ],
        ],
    ],
];
