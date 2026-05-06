<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * EEA + Switzerland (frontend parity for work-permit exemption).
     *
     * @var list<string>
     */
    private const RESIDENCE_TITLE_EXEMPT_COUNTRY_CODES = [
        'AT',
        'BE',
        'BG',
        'CH',
        'CY',
        'CZ',
        'DE',
        'DK',
        'EE',
        'ES',
        'FI',
        'FR',
        'GR',
        'HR',
        'HU',
        'IE',
        'IS',
        'IT',
        'LI',
        'LT',
        'LU',
        'LV',
        'MT',
        'NL',
        'NO',
        'PL',
        'PT',
        'RO',
        'SE',
        'SI',
        'SK',
    ];

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

        if ($personalInformationTemplate !== null && is_string($personalInformationTemplate->id) && is_string($nationalityTemplateId)) {
            $this->migrateLegacyNationalitySubmissions($personalInformationTemplate->id, $nationalityTemplateId);
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

        $personalInformationTemplate = DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'personal_information_form')
            ->first(['id']);

        $nationalityTemplate = DB::table('onboarding_form_templates')
            ->whereNull('tenant_id')
            ->where('template_key', 'nationality_and_residence')
            ->first(['id']);

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

        if (is_string($personalInformationTemplate?->id) && is_string($nationalityTemplate?->id)) {
            $this->mergeNationalitySubmissionsBackIntoPersonalInformation(
                $personalInformationTemplate->id,
                $nationalityTemplate->id,
            );
        }

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

                $submissionState = $this->resolveMigratedSubmissionState($submission, $formData);

                OnboardingFormSubmission::query()->create([
                    'employee_id' => $submission->employee_id,
                    'form_template_id' => $nationalityTemplateId,
                    'form_data' => $formData,
                    'status' => $submissionState['status'],
                    'submitted_at' => $submissionState['submitted_at'],
                    'reviewed_by' => $submissionState['reviewed_by'],
                    'reviewed_at' => $submissionState['reviewed_at'],
                    'review_notes' => $submissionState['review_notes'],
                    'created_at' => $submission->created_at,
                    'updated_at' => $submission->updated_at,
                ]);
            });
    }

    private function mergeNationalitySubmissionsBackIntoPersonalInformation(
        string $personalInformationTemplateId,
        string $nationalityTemplateId,
    ): void {
        OnboardingFormSubmission::query()
            ->where('form_template_id', $nationalityTemplateId)
            ->orderBy('id')
            ->get()
            ->each(function (OnboardingFormSubmission $submission) use ($personalInformationTemplateId): void {
                $formData = $this->extractNationalityAndResidenceFormData($submission->form_data);
                if ($formData === null) {
                    return;
                }

                $personalInformationSubmission = OnboardingFormSubmission::query()
                    ->where('employee_id', $submission->employee_id)
                    ->where('form_template_id', $personalInformationTemplateId)
                    ->first();

                if ($personalInformationSubmission !== null) {
                    $existingFormData = is_array($personalInformationSubmission->form_data)
                        ? $personalInformationSubmission->form_data
                        : [];

                    $personalInformationSubmission->forceFill([
                        'form_data' => array_merge($existingFormData, $formData),
                    ])->save();

                    return;
                }

                OnboardingFormSubmission::query()->create([
                    'employee_id' => $submission->employee_id,
                    'form_template_id' => $personalInformationTemplateId,
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

        $formData = [
            'nationalities' => $normalizedNationalities,
        ];

        $residenceTitle = $this->normalizedNonEmptyString($legacyFormData['residence_permit_title'] ?? null);
        if ($residenceTitle !== null) {
            $formData['residence_permit_title'] = $residenceTitle;
        }

        $employmentAllowed = $this->normalizedNonEmptyString($legacyFormData['residence_permit_employment_allowed'] ?? null);
        if ($employmentAllowed !== null) {
            $formData['residence_permit_employment_allowed'] = strtolower($employmentAllowed);
        }

        $residencePermitUnlimited = $this->normalizedBoolean($legacyFormData['residence_permit_unlimited'] ?? null);
        if ($residencePermitUnlimited !== null) {
            $formData['residence_permit_unlimited'] = $residencePermitUnlimited;
        }

        $residencePermitExpiry = $this->normalizedNonEmptyString($legacyFormData['residence_permit_expiry'] ?? null);
        if ($residencePermitExpiry !== null) {
            $formData['residence_permit_expiry'] = $residencePermitExpiry;
        }

        return $formData;
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array{
     *     status: string,
     *     submitted_at: ?Carbon,
     *     reviewed_by: ?string,
     *     reviewed_at: ?Carbon,
     *     review_notes: ?string
     * }
     */
    private function resolveMigratedSubmissionState(OnboardingFormSubmission $submission, array $formData): array
    {
        if (! $this->shouldResetMigratedSubmissionStatus($submission->status, $formData)) {
            return [
                'status' => $submission->status,
                'submitted_at' => $submission->submitted_at,
                'reviewed_by' => $submission->reviewed_by,
                'reviewed_at' => $submission->reviewed_at,
                'review_notes' => $submission->review_notes,
            ];
        }

        $employee = $this->resolveEmployeeForSubmission($submission);

        if (! $this->shouldReopenEmployeeOnboarding($employee)) {
            return [
                'status' => $submission->status,
                'submitted_at' => $submission->submitted_at,
                'reviewed_by' => $submission->reviewed_by,
                'reviewed_at' => $submission->reviewed_at,
                'review_notes' => $submission->review_notes,
            ];
        }

        if ($employee !== null) {
            DB::table('employees')
                ->where('id', $employee->id)
                ->update([
                    'onboarding_completed' => false,
                    'onboarding_completed_at' => null,
                    'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_IN_PROGRESS,
                    'updated_at' => now(),
                ]);
        }

        return [
            'status' => 'draft',
            'submitted_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
        ];
    }

    private function resolveEmployeeForSubmission(OnboardingFormSubmission $submission): ?object
    {
        if (! is_string($submission->employee_id) || $submission->employee_id === '') {
            return null;
        }

        return DB::table('employees')
            ->where('id', $submission->employee_id)
            ->first(['id', 'status']);
    }

    private function shouldReopenEmployeeOnboarding(?object $employee): bool
    {
        return is_object($employee)
            && is_string($employee->status ?? null)
            && $employee->status === 'pre_contract';
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function shouldResetMigratedSubmissionStatus(string $status, array $formData): bool
    {
        if (! in_array($status, ['submitted', 'approved'], true)) {
            return false;
        }

        if (! $this->requiresResidencePermitData($formData)) {
            return false;
        }

        return ! $this->hasValidResidencePermitData($formData);
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function requiresResidencePermitData(array $formData): bool
    {
        $nationalities = $this->normalizedNationalityCodes($formData['nationalities'] ?? null);
        if ($nationalities === []) {
            return false;
        }

        foreach ($nationalities as $code) {
            if (! in_array($code, self::RESIDENCE_TITLE_EXEMPT_COUNTRY_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function hasValidResidencePermitData(array $formData): bool
    {
        $residenceTitle = $this->normalizedNonEmptyString($formData['residence_permit_title'] ?? null);
        if ($residenceTitle === null) {
            return false;
        }

        $employmentAllowed = strtolower(
            $this->normalizedNonEmptyString($formData['residence_permit_employment_allowed'] ?? null) ?? ''
        );

        if ($employmentAllowed !== 'yes') {
            return false;
        }

        if ($this->isResidencePermitUnlimited($formData)) {
            return true;
        }

        $expiryDateString = $this->normalizedNonEmptyString($formData['residence_permit_expiry'] ?? null);
        if ($expiryDateString === null) {
            return false;
        }

        try {
            $expiryDate = Carbon::createFromFormat('Y-m-d', $expiryDateString);
        } catch (Throwable) {
            return false;
        }

        if (! $expiryDate instanceof Carbon) {
            return false;
        }

        if ($expiryDate->format('Y-m-d') !== $expiryDateString) {
            return false;
        }

        return ! $expiryDate->startOfDay()->lt(now()->startOfDay());
    }

    /**
     * @return list<string>
     */
    private function normalizedNationalityCodes(mixed $nationalities): array
    {
        if (! is_array($nationalities)) {
            return [];
        }

        $codes = [];

        foreach ($nationalities as $entry) {
            if (! is_string($entry) && ! is_int($entry)) {
                continue;
            }

            $normalized = strtoupper(trim((string) $entry));
            if (preg_match('/^[A-Z]{2}$/', $normalized) !== 1) {
                continue;
            }

            $codes[] = $normalized;
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function isResidencePermitUnlimited(array $formData): bool
    {
        return $this->normalizedBoolean($formData['residence_permit_unlimited'] ?? null) ?? false;
    }

    private function normalizedNonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizedBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                1 => true,
                0 => false,
                default => null,
            };
        }

        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }
};
