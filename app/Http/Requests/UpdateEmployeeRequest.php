<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateEmployeeRequest validates Employee update requests.
 *
 * All fields are optional (PATCH semantics).
 * Email uniqueness excludes the current employee.
 */
class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Employee|null $employee */
        $employee = $this->route('employee');

        return $employee !== null && ($this->user()?->can('update', $employee) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $employeeId = $this->route('employee');

        return [
            // Personal Data (will be encrypted)
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'email' => ['sometimes', 'required', 'email', Rule::unique('employees', 'email')->ignore($employeeId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:255'],

            // BewachV § 16 Abs. 2 Nr. 1: BWR Tracking
            'bwr_id' => [
                'sometimes',
                'nullable',
                'string',
                'size:7',
                'regex:/^[0-9]{7}$/',
                Rule::unique('employees', 'bwr_id')->ignore($employeeId),
            ],
            'bwr_status' => ['sometimes', 'nullable', Rule::in(['not_registered', 'pending', 'active', 'suspended', 'revoked'])],
            'bwr_registered_at' => ['sometimes', 'nullable', 'date'],
            'bwr_submission_date' => ['sometimes', 'nullable', 'date'],
            'bwr_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // BewachV § 16 Abs. 2 Nr. 2: Identity Data
            'gender' => ['sometimes', 'nullable', Rule::in(['male', 'female', 'diverse'])],
            'birth_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'previous_names' => ['sometimes', 'nullable', 'array'],
            'previous_names.*' => ['string', 'max:255'],

            // BewachV § 16 Abs. 2 Nr. 3: Birth Place
            'birth_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birth_country' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'birth_state' => ['sometimes', 'nullable', 'string', 'max:100'],

            // BewachV § 16 Abs. 2 Nr. 4: Nationalities
            'nationalities' => ['sometimes', 'nullable', 'array'],
            'nationalities.*' => ['string', 'size:2', 'regex:/^[A-Z]{2}$/'],

            // BewachV § 16 Abs. 2 Nr. 5: Structured Current Address
            'address_street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_house_number' => ['sometimes', 'nullable', 'string', 'max:10'],
            'address_postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_supplement' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_country' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'address_state' => ['sometimes', 'nullable', 'string', 'max:100'],

            // BewachV § 16 Abs. 2 Nr. 6: Address History
            'address_history' => ['sometimes', 'nullable', 'array'],
            'address_history.*.from' => ['required', 'date'],
            'address_history.*.to' => ['required', 'date', 'after_or_equal:address_history.*.from'],
            'address_history.*.street' => ['required', 'string', 'max:255'],
            'address_history.*.city' => ['required', 'string', 'max:255'],
            'address_history.*.postal_code' => ['required', 'string', 'max:20'],
            'address_history.*.country' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],

            // BewachV § 16 Abs. 2 Nr. 7: Intended Activities
            'intended_activities' => ['sometimes', 'nullable', 'array'],
            'intended_activities.*' => ['string', 'max:100'],

            // BewachV § 16 Abs. 2 Nr. 11: ID Document
            'id_document_type' => ['sometimes', 'nullable', Rule::in(['passport', 'id_card', 'residence_permit'])],
            'id_document_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'id_document_expiry' => ['sometimes', 'nullable', 'date', 'after:today'],
            // NOTE: id_document_copy_path is NOT client-writable (security risk)
            // This field is set server-side during file upload via dedicated upload endpoint

            // Retention & Employment End
            'employment_end_date' => ['sometimes', 'nullable', 'date'],
            'retention_period_end' => ['sometimes', 'nullable', 'date'],

            // Tax & Social Security (will be encrypted)
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'social_security_number' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Employment Status
            'status' => ['missing'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'management_level' => ['sometimes', 'required', 'integer', 'min:0', 'max:255'],
            'hire_date' => ['sometimes', 'nullable', 'date'],
            'contract_start_date' => ['sometimes', 'nullable', 'date'],
            'termination_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:contract_start_date'],
            'last_working_day' => ['sometimes', 'nullable', 'date'],

            // Contract Details
            'contract_type' => ['sometimes', 'required', Rule::in(['full_time', 'part_time', 'minijob', 'freelance'])],
            'weekly_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:60'],
            'monthly_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:300'],
            'hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // Health Insurance
            'health_insurance_type' => ['sometimes', 'nullable', Rule::in(['public', 'private', 'foreign'])],
            'health_insurance_provider' => ['sometimes', 'nullable', 'string', 'max:255'],
            'health_insurance_number' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Legal Requirements (BewachV § 34a - Sachkunde)
            // Note: Sachkunde qualification NEVER expires - valid for life!
            'sachkunde_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sachkunde_certificate' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sachkunde_ihk_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sachkunde_exam_date' => ['sometimes', 'nullable', 'date'],
            'sachkunde_issued_date' => ['sometimes', 'nullable', 'date'],

            // Work & Residence Permits
            'work_permit_type' => ['sometimes', 'nullable', Rule::in(['unlimited', 'limited', 'none'])],
            'work_permit_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'work_permit_expiry' => ['sometimes', 'nullable', 'date'],
            'residence_permit_type' => ['sometimes', 'nullable', Rule::in(['unlimited', 'limited', 'none'])],
            'residence_permit_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'residence_permit_expiry' => ['sometimes', 'nullable', 'date'],

            // Criminal Record
            'criminal_record_status' => ['sometimes', 'nullable', Rule::in(['valid', 'expired', 'pending'])],
            'criminal_record_check_date' => ['sometimes', 'nullable', 'date'],

            // Organizational - Security: Validate user has access to selected unit
            'organizational_unit_id' => [
                'sometimes',
                'nullable',
                Rule::exists('organizational_units', 'id')->where(function (\Illuminate\Database\Query\Builder $query): void {
                    /** @var string $tenantId */
                    $tenantId = $this->input('tenant_id');
                    $query->where('tenant_id', $tenantId);
                }),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    /** @var \App\Models\User $user */
                    $user = $this->user();

                    // If user has organizational scopes, verify access to the selected unit
                    $hasScopes = $user->organizationalScopes()->exists();
                    if ($hasScopes) {
                        $accessibleUnitIds = $user->getAccessibleOrganizationalUnits()->pluck('id')->toArray();
                        if (! in_array($value, $accessibleUnitIds, true)) {
                            $fail(__('You do not have access to the selected organizational unit.'));
                        }
                    }
                },
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.email' => __('Email address must be valid'),
            'email.unique' => __('Email address is already in use'),
            'status.missing' => __('Employee status transitions must use the dedicated activate, leave, return-from-leave, or terminate endpoints.'),
            'termination_date.after_or_equal' => __('Termination date must be after or equal to contract start date'),

            // BWR-ID validation
            'bwr_id.size' => 'Die Bewacher-ID muss exakt 7 Ziffern haben.',
            'bwr_id.regex' => 'Die Bewacher-ID darf nur Ziffern enthalten (0000000-9999999).',
            'bwr_id.unique' => 'Diese Bewacher-ID ist bereits vergeben.',

            // ISO country codes
            'birth_country.size' => 'Geburtsland muss ISO-Code mit 2 Buchstaben sein (z.B. DE, PL).',
            'birth_country.regex' => 'Geburtsland muss aus 2 Großbuchstaben bestehen.',
            'address_country.size' => 'Land muss ISO-Code mit 2 Buchstaben sein (z.B. DE, PL).',
            'address_country.regex' => 'Land muss aus 2 Großbuchstaben bestehen.',
            'nationalities.*.size' => 'Staatsangehörigkeit muss ISO-Code mit 2 Buchstaben sein (z.B. DE, TR).',
            'nationalities.*.regex' => 'Staatsangehörigkeit muss aus 2 Großbuchstaben bestehen.',

            // Address history
            'address_history.*.to.after_or_equal' => 'End-Datum muss nach Start-Datum liegen.',
        ];
    }
}
