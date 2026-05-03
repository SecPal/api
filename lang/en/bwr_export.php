<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'missing_fields' => [
        'first_name' => 'First name must be set on the employee record.',
        'last_name' => 'Last name must be set on the employee record.',
        'date_of_birth' => 'Date of birth must be set on the employee record.',
        'gender' => 'Gender must be set on the employee record.',
        'birth_city' => 'Place of birth (city) must be set on the employee record.',
        'birth_country' => 'Place of birth (country) must be set on the employee record.',
        'nationalities' => 'Nationalities must be set on the employee record.',
        'address_street' => 'Street must be set in the employee address.',
        'address_house_number' => 'House number must be set in the employee address.',
        'address_postal_code' => 'Postal code must be set in the employee address.',
        'address_city' => 'City must be set in the employee address.',
        'address_country' => 'Country must be set in the employee address.',
        'address_history' => 'Address history is required for export (add past addresses in the employee profile).',
        'intended_activities' => 'Planned activities under Section 34a GewO must be set on the employee before export (typically by HR if not collected during onboarding).',
        'id_document_type' => 'ID document type must be set on the employee record.',
        'id_document_number' => 'ID document number must be set on the employee record.',
        'id_document_expiry' => 'ID document expiry date must be set on the employee record.',
        'sachkunde_type' => 'Qualification type (Sachkunde / §34a classification) must be set before export.',
        'sachkunde_certificate' => 'Qualification certificate reference must be set before export.',
        'id_document_expiry_expired' => 'ID document has expired; update the expiry date before export.',
        'valid_work_authorization' => 'Valid work authorization is required for this nationality before export.',
    ],
];
