<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $personalInformationSchema = [
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

        $nationalityAndResidenceSchema = [
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

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'personal_information_form')
            ->update([
                'name' => 'Personal Information Form',
                'description' => 'Your personal details for onboarding; HR may need to complete additional Bewacherregister fields before export.',
                'form_schema' => json_encode($personalInformationSchema, JSON_THROW_ON_ERROR),
                'sort_order' => 1,
                'updated_at' => $now,
            ]);

        $existingNationalityTemplate = DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'nationality_and_residence')
            ->first();

        $nationalityTemplatePayload = [
            'name' => 'Nationality and Residence',
            'description' => 'Nationality, residence title, and employment authorization status.',
            'form_schema' => json_encode($nationalityAndResidenceSchema, JSON_THROW_ON_ERROR),
            'is_required' => true,
            'is_system_template' => true,
            'sort_order' => 2,
            'updated_at' => $now,
        ];

        if ($existingNationalityTemplate) {
            DB::table('onboarding_form_templates')
                ->where('id', $existingNationalityTemplate->id)
                ->update($nationalityTemplatePayload);
        } else {
            DB::table('onboarding_form_templates')->insert(array_merge($nationalityTemplatePayload, [
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'template_key' => 'nationality_and_residence',
                'created_at' => $now,
            ]));
        }

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'bank_account_details')
            ->update([
                'sort_order' => 3,
                'updated_at' => $now,
            ]);

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'emergency_contact')
            ->update([
                'sort_order' => 4,
                'updated_at' => $now,
            ]);

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'tax_identification_number')
            ->update([
                'sort_order' => 5,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        $now = now();

        $legacyPersonalInformationSchema = [
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
            'required' => ['gender', 'nationalities'],
        ];

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'personal_information_form')
            ->update([
                'form_schema' => json_encode($legacyPersonalInformationSchema, JSON_THROW_ON_ERROR),
                'sort_order' => 1,
                'updated_at' => $now,
            ]);

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'nationality_and_residence')
            ->delete();

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'bank_account_details')
            ->update([
                'sort_order' => 2,
                'updated_at' => $now,
            ]);

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'emergency_contact')
            ->update([
                'sort_order' => 3,
                'updated_at' => $now,
            ]);

        DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'tax_identification_number')
            ->update([
                'sort_order' => 4,
                'updated_at' => $now,
            ]);
    }
};
