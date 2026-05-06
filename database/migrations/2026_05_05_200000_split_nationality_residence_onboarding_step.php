<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OnboardingFormSubmission;
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

        $personalInformationTemplate = DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'personal_information_form')
            ->first(['id']);

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

        $nationalityTemplateId = $existingNationalityTemplate?->id;

        if ($existingNationalityTemplate) {
            DB::table('onboarding_form_templates')
                ->where('id', $existingNationalityTemplate->id)
                ->update($nationalityTemplatePayload);
        } else {
            $nationalityTemplateId = (string) Str::uuid();

            DB::table('onboarding_form_templates')->insert(array_merge($nationalityTemplatePayload, [
                'id' => $nationalityTemplateId,
                'tenant_id' => null,
                'template_key' => 'nationality_and_residence',
                'created_at' => $now,
            ]));
        }

        if ($personalInformationTemplate !== null && is_string($nationalityTemplateId)) {
            $this->migrateLegacyNationalitySubmissions((string) $personalInformationTemplate->id, $nationalityTemplateId);
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

    private function migrateLegacyNationalitySubmissions(string $personalInformationTemplateId, string $nationalityTemplateId): void
    {
        OnboardingFormSubmission::query()
            ->where('form_template_id', $personalInformationTemplateId)
            ->orderBy('id')
            ->get()
            ->each(function (OnboardingFormSubmission $submission) use ($nationalityTemplateId): void {
                $formData = $this->extractNationalityAndResidenceFormData($submission->form_data);

                if ($formData === null) {
                    return;
                }

                $alreadyMigrated = OnboardingFormSubmission::query()
                    ->where('employee_id', $submission->employee_id)
                    ->where('form_template_id', $nationalityTemplateId)
                    ->exists();

                if ($alreadyMigrated) {
                    return;
                }

                OnboardingFormSubmission::query()->create([
                    'employee_id' => $submission->employee_id,
                    'form_template_id' => $nationalityTemplateId,
                    'form_data' => $formData,
                    'status' => $submission->status,
                    'submitted_at' => $submission->submitted_at,
                    'reviewed_by' => $submission->reviewed_by,
                    'reviewed_at' => $submission->reviewed_at,
                    'review_notes' => $submission->review_notes,
                    'created_at' => $submission->created_at,
                    'updated_at' => $submission->updated_at,
                ]);
            });
    }

    /**
     * @param  array<string, mixed>|null  $legacyFormData
     * @return array<string, mixed>|null
     */
    private function extractNationalityAndResidenceFormData(?array $legacyFormData): ?array
    {
        if (! is_array($legacyFormData)) {
            return null;
        }

        $nationalities = $legacyFormData['nationalities'] ?? null;
        if (! is_array($nationalities)) {
            return null;
        }

        $normalizedNationalities = [];

        foreach ($nationalities as $nationality) {
            if (! is_string($nationality) && ! is_int($nationality)) {
                continue;
            }

            $normalizedNationality = strtoupper(trim((string) $nationality));
            if (preg_match('/^[A-Z]{2}$/', $normalizedNationality) !== 1) {
                continue;
            }

            $normalizedNationalities[] = $normalizedNationality;
        }

        $normalizedNationalities = array_values(array_unique($normalizedNationalities));

        if ($normalizedNationalities === []) {
            return null;
        }

        return [
            'nationalities' => $normalizedNationalities,
        ];
    }
};
