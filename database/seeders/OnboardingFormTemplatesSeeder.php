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
                'description' => 'BewachV § 16 required information for Bewacherregister',
                'form_schema' => $this->getPersonalInformationSchema(),
                'is_required' => true,
                'is_system_template' => true,
                'sort_order' => 1,
                'tenant_id' => null,
            ],
            [
                'name' => 'Bank Account Details',
                'template_key' => 'bank_account_details',
                'description' => 'Account information for salary payment',
                'form_schema' => $this->getBankAccountSchema(),
                'is_required' => false,
                'is_system_template' => true,
                'sort_order' => 2,
                'tenant_id' => null,
            ],
            [
                'name' => 'Emergency Contact',
                'template_key' => 'emergency_contact',
                'description' => 'Emergency contact persons',
                'form_schema' => $this->getEmergencyContactSchema(),
                'is_required' => false,
                'is_system_template' => true,
                'sort_order' => 3,
                'tenant_id' => null,
            ],
            [
                'name' => 'Tax Identification Number',
                'template_key' => 'tax_identification_number',
                'description' => 'Tax ID and tax class information (§ 39e EStG)',
                'form_schema' => $this->getTaxIdentificationSchema(),
                'is_required' => false,
                'is_system_template' => true,
                'sort_order' => 4,
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

        $this->command->info('Created 4 standard onboarding form templates.');
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
            'description' => 'BewachV § 16 required information',
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
                'nationalities' => [
                    'type' => 'array',
                    'title' => 'Nationalities',
                    'items' => [
                        'type' => 'string',
                        'pattern' => '^[A-Z]{2}$',
                    ],
                    'minItems' => 1,
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
                    ],
                    'minItems' => 1,
                ],
            ],
            'required' => ['gender', 'nationalities', 'intended_activities'],
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
            'description' => 'Emergency contact persons',
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
            'required' => ['contact_1_name', 'contact_1_phone', 'contact_1_relationship'],
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
            'description' => 'Optional eleven-digit tax identification number (§ 39e EStG)',
            'type' => 'object',
            'properties' => [
                'tax_id' => [
                    'type' => 'string',
                    'title' => 'Tax Identification Number',
                    'pattern' => '^\d{11}$',
                ],
                'children_count' => [
                    'type' => 'integer',
                    'title' => 'Number of Children',
                    'minimum' => 0,
                ],
            ],
            'required' => [],
        ];
    }
}
