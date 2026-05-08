<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Seeders;

use App\Models\OnboardingFormTemplate;
use Illuminate\Database\Seeder;

class OnboardingFormTemplatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Personal Information Form',
                'template_key' => 'personal_information_form',
                'description' => 'Your personal details for onboarding; HR may need to complete additional Bewacherregister fields before export.',
                'form_schema' => $this->getPersonalInformationSchema(),
                'is_required' => true,
                'is_system_template' => true,
                'sort_order' => 1,
                'tenant_id' => null,
            ],
            [
                'name' => 'Nationality and Residence',
                'template_key' => 'nationality_and_residence',
                'description' => 'Nationality, residence title, and employment authorization status.',
                'form_schema' => $this->getNationalityAndResidenceSchema(),
                'is_required' => true,
                'is_system_template' => true,
                'sort_order' => 2,
                'tenant_id' => null,
            ],
            [
                'name' => 'Bank Account Details',
                'template_key' => 'bank_account_details',
                'description' => 'Account information for salary payment',
                'form_schema' => $this->getBankAccountSchema(),
                'is_required' => false,
                'is_system_template' => true,
                'sort_order' => 3,
                'tenant_id' => null,
            ],
            [
                'name' => 'Emergency Contact',
                'template_key' => 'emergency_contact',
                'description' => 'Optional emergency contact persons',
                'form_schema' => $this->getEmergencyContactSchema(),
                'is_required' => false,
                'is_system_template' => true,
                'sort_order' => 4,
                'tenant_id' => null,
            ],
            [
                'name' => 'Tax Identification Number',
                'template_key' => 'tax_identification_number',
                'description' => 'Required eleven-digit tax identification number (§ 39e EStG) and social security number.',
                'form_schema' => $this->getTaxIdentificationSchema(),
                'is_required' => true,
                'is_system_template' => true,
                'sort_order' => 5,
                'tenant_id' => null,
            ],
        ];

        foreach ($templates as $template) {
            OnboardingFormTemplate::updateOrCreate(
                [
                    'template_key' => $template['template_key'],
                    'tenant_id' => null,
                ],
                $template
            );
        }

        $this->command->info('Created 5 standard onboarding form templates.');
    }

    /**
     * Get JSON schema for Personal Information Form (BewachV § 16 fields).
     *
     * @return array<string, mixed>
     */
    private function getPersonalInformationSchema(): array
    {
        return [
            'title' => 'Personal Information Form',
            'description' => 'Information required for onboarding; planned activities under §34a GewO can be completed later by HR for Bewacherregister export.',
            'type' => 'object',
            'properties' => [
                'gender' => [
                    'type' => 'string',
                    'title' => 'Gender',
                    'enum' => ['male', 'female', 'diverse'],
                ],
                'birth_name' => [
                    'type' => 'string',
                    'title' => 'Birth Name',
                    'maxLength' => 100,
                ],
                'previous_names' => [
                    'type' => 'array',
                    'title' => 'Previous Names',
                    'items' => [
                        'type' => 'string',
                        'maxLength' => 100,
                    ],
                ],
                'intended_activities' => [
                    'type' => 'array',
                    'title' => 'Intended Activities (§ 34a GewO)',
                    'description' => 'Optional during onboarding; HR must align this with the assignment before Bewacherregister export if you skip it.',
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
                    ],
                ],
            ],
            'required' => ['gender'],
        ];
    }

    /**
     * Get JSON schema for Nationality and Residence step.
     *
     * @return array<string, mixed>
     */
    private function getNationalityAndResidenceSchema(): array
    {
        return [
            'title' => 'Nationality and Residence',
            'description' => 'Nationality, residence title, and employment authorization status.',
            'type' => 'object',
            'properties' => [
                'nationalities' => [
                    'type' => 'array',
                    'title' => 'Nationalities',
                    'items' => [
                        'type' => 'string',
                        'pattern' => '^[A-Z]{2}$',
                    ],
                    'minItems' => 1,
                ],
                'residence_permit_title' => [
                    'type' => 'string',
                    'title' => 'Residence Title',
                ],
                'residence_permit_employment_allowed' => [
                    'type' => 'string',
                    'title' => 'Employment Authorization',
                    'enum' => ['yes', 'no'],
                ],
                'residence_permit_unlimited' => [
                    'type' => 'boolean',
                    'title' => 'Residence Title Is Unlimited',
                ],
                'residence_permit_expiry' => [
                    'type' => 'string',
                    'title' => 'Residence Title Expiry Date',
                    'pattern' => '^\d{4}-\d{2}-\d{2}$',
                ],
            ],
            'required' => ['nationalities'],
        ];
    }

    /**
     * Get JSON schema for Bank Account Details.
     *
     * @return array<string, mixed>
     */
    private function getBankAccountSchema(): array
    {
        return [
            'title' => 'Bank Account Details',
            'description' => 'For salary payment',
            'type' => 'object',
            'properties' => [
                'iban' => [
                    'type' => 'string',
                    'title' => 'IBAN',
                    'pattern' => '^[A-Z]{2}\d{2}[A-Z0-9]+$',
                    'maxLength' => 34,
                ],
                'bic' => [
                    'type' => 'string',
                    'title' => 'BIC/SWIFT',
                    'pattern' => '^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$',
                ],
                'bank_name' => [
                    'type' => 'string',
                    'title' => 'Bank Name',
                    'maxLength' => 100,
                ],
                'account_holder' => [
                    'type' => 'string',
                    'title' => 'Account Holder',
                    'maxLength' => 100,
                    'default' => '{{employee.full_name}}',
                ],
            ],
            'required' => ['iban', 'account_holder'],
        ];
    }

    /**
     * Get JSON schema for Emergency Contact.
     *
     * @return array<string, mixed>
     */
    private function getEmergencyContactSchema(): array
    {
        return [
            'title' => 'Emergency Contact',
            'description' => 'Optional emergency contact persons',
            'type' => 'object',
            'properties' => [
                'contact_1_name' => [
                    'type' => 'string',
                    'title' => 'Contact 1: Name',
                    'maxLength' => 100,
                ],
                'contact_1_phone' => [
                    'type' => 'string',
                    'title' => 'Contact 1: Phone',
                    'pattern' => '^\+?[0-9\s\-\(\)]+$',
                ],
                'contact_1_relationship' => [
                    'type' => 'string',
                    'title' => 'Contact 1: Relationship',
                    'enum' => [
                        'spouse',
                        'partner',
                        'parent',
                        'sibling',
                        'child',
                        'friend',
                        'other',
                    ],
                ],
                'contact_2_name' => [
                    'type' => 'string',
                    'title' => 'Contact 2: Name',
                    'maxLength' => 100,
                ],
                'contact_2_phone' => [
                    'type' => 'string',
                    'title' => 'Contact 2: Phone',
                    'pattern' => '^\+?[0-9\s\-\(\)]+$',
                ],
                'contact_2_relationship' => [
                    'type' => 'string',
                    'title' => 'Contact 2: Relationship',
                    'enum' => [
                        'spouse',
                        'partner',
                        'parent',
                        'sibling',
                        'child',
                        'friend',
                        'other',
                    ],
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Get JSON schema for Tax Identification Number.
     *
     * @return array<string, mixed>
     */
    private function getTaxIdentificationSchema(): array
    {
        return [
            'title' => 'Tax Identification Number',
            'description' => 'Required eleven-digit tax identification number (§ 39e EStG) and social security number.',
            'type' => 'object',
            'properties' => [
                'tax_id' => [
                    'type' => 'string',
                    'title' => 'Tax Identification Number',
                    'pattern' => '^\d{11}$',
                ],
                'social_security_number' => [
                    'type' => 'string',
                    'title' => 'Social Security Number',
                    'pattern' => '^\d{2}\s?\d{6}\s?[A-Z]\s?\d{3}$',
                ],
                'children_count' => [
                    'type' => 'integer',
                    'title' => 'Number of Children',
                    'minimum' => 0,
                ],
            ],
            'required' => ['tax_id', 'social_security_number'],
        ];
    }
}
