<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Http\Requests\Concerns\InteractsWithCertificationValidation;
use App\Http\Requests\Concerns\InteractsWithEmployeeAddressValidation;
use App\Http\Requests\Concerns\InteractsWithWorkPermitValidation;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Policies\EmployeePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * StoreEmployeeRequest validates Employee creation requests.
 *
 * All personal data fields will be encrypted by the Employee model mutators.
 */
class StoreEmployeeRequest extends FormRequest
{
    use InteractsWithCertificationValidation;
    use InteractsWithEmployeeAddressValidation;
    use InteractsWithWorkPermitValidation;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('organizational_unit_id') || $validator->errors()->has('management_level')) {
                return;
            }

            $this->validateEmployeeScopeConstraints($validator);
            $this->validateEmployeeAddressesPayload($validator);
        });
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            // Personal Data (will be encrypted)
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'email' => ['required', 'email', 'unique:employees,email'],
            'phone' => ['nullable', 'string', 'max:255'],
            'photo_path' => ['nullable', 'string', 'max:255'],

            // BewachV § 16 Abs. 2 Nr. 1: BWR Tracking
            'bwr_id' => [
                'nullable',
                'string',
                'size:7',
                'regex:/^[0-9]{7}$/',
                'unique:employees,bwr_id',
            ],
            'bwr_status' => ['missing'],
            'bwr_registered_at' => ['missing'],
            'bwr_submission_date' => ['nullable', 'date'],
            'bwr_notes' => ['nullable', 'string', 'max:1000'],

            // BewachV § 16 Abs. 2 Nr. 2: Identity Data
            'gender' => [
                'required_if:bwr_status,pending,active', // MANDATORY for BWR submission
                Rule::in(['male', 'female', 'diverse']),
            ],
            'birth_name' => ['nullable', 'string', 'max:255'], // Will be encrypted
            'previous_names' => ['nullable', 'array'], // JSON array of strings
            'previous_names.*' => ['string', 'max:255'],

            // BewachV § 16 Abs. 2 Nr. 3: Birth Place
            'birth_city' => ['nullable', 'string', 'max:255'],
            'birth_country' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'], // ISO 3166-1 alpha-2 Geburtsland

            // BewachV § 16 Abs. 2 Nr. 4: Nationalities (supports dual citizenship)
            'nationalities' => ['nullable', 'array'], // JSON array of ISO codes
            'nationalities.*' => ['string', 'size:2', 'regex:/^[A-Z]{2}$/'], // e.g., ["DE", "PL"]

            'addresses' => ['required_if:bwr_status,pending,active', 'nullable', 'array'],

            // Emergency contacts
            'emergency_contacts' => ['nullable', 'array'],
            'emergency_contacts.*.name' => ['required', 'string', 'max:255'],
            'emergency_contacts.*.relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contacts.*.phone' => ['required', 'string', 'max:255'],
            'emergency_contacts.*.email' => ['nullable', 'email', 'max:255'],
            'emergency_contacts.*.notes' => ['nullable', 'string', 'max:500'],

            // BewachV § 16 Abs. 2 Nr. 7: Intended Activities
            'intended_activities' => ['nullable', 'array'], // JSON array of activity codes
            'intended_activities.*' => ['string', 'max:100'],

            // BewachV § 16 Abs. 2 Nr. 11: ID Document
            'id_document_type' => ['nullable', Rule::in(['passport', 'id_card', 'residence_permit'])],
            'id_document_number' => ['nullable', 'string', 'max:255'], // Will be encrypted
            'id_document_expiry' => ['nullable', 'date', 'after:today'],
            // NOTE: id_document_copy_path is NOT client-writable (security risk)
            // This field is set server-side during file upload via dedicated upload endpoint

            // Retention & Employment End (BewachV § 21)
            'employment_end_date' => ['missing'],
            'retention_period_end' => ['missing'],

            // Tax & Social Security (will be encrypted)
            'tax_id' => ['nullable', 'string', 'max:255'],
            'social_security_number' => ['nullable', 'string', 'max:255'],

            // Employment Status
            'status' => ['required', Rule::in(Employee::VALID_STATUSES)],
            'position' => ['required', 'string', 'max:255'],
            'management_level' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'contract_start_date' => ['required', 'date'],
            'termination_date' => ['nullable', 'date', 'after_or_equal:contract_start_date'],
            'last_working_day' => ['nullable', 'date'],

            // Contract Details
            'contract_type' => ['required', Rule::in(['full_time', 'part_time', 'minijob', 'freelance'])],
            'send_invitation' => [
                'sometimes',
                'boolean',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $statusInput = $this->input('status');
                    $status = is_string($statusInput) ? $statusInput : '';

                    if ((bool) $value && ! in_array($status, Employee::INVITABLE_STATUSES, true)) {
                        $fail(sprintf(
                            'Invitation sending is only available when employee status is %s. Received: %s.',
                            implode(', ', Employee::INVITABLE_STATUSES),
                            $status !== '' ? $status : 'none',
                        ));
                    }
                },
            ],
            'weekly_hours' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'monthly_hours' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],

            // Health Insurance
            'health_insurance_type' => ['nullable', Rule::in(['public', 'private', 'foreign'])],
            'health_insurance_provider' => ['nullable', 'string', 'max:255'],
            'health_insurance_number' => ['nullable', 'string', 'max:255'],

            // Legal Requirements (BewachV § 34a - Sachkunde)
            // Note: Sachkunde qualification NEVER expires - valid for life!
            'sachkunde_type' => ['nullable', 'string', 'max:255'],
            'sachkunde_certificate' => ['nullable', 'string', 'max:255'],
            'sachkunde_ihk_number' => ['nullable', 'string', 'max:50'], // IHK certificate number
            'sachkunde_exam_date' => ['nullable', 'date'], // Exam date
            'sachkunde_issued_date' => ['nullable', 'date'], // Certificate issue date

            // Work & Residence Permits
            'work_permit_type' => [
                Rule::requiredIf(fn (): bool => $this->requiresWorkPermitForCurrentPayload()),
                'nullable',
                Rule::in(Employee::VALID_WORK_PERMIT_TYPES),
            ],
            'work_permit_number' => [
                Rule::requiredIf(fn (): bool => $this->requiresWorkPermitDetailsForCurrentPayload()),
                'nullable',
                'string',
                'max:255',
            ],
            'work_permit_expiry' => [
                Rule::requiredIf(fn (): bool => $this->requiresWorkPermitExpiryForCurrentPayload()),
                'nullable',
                'date',
                'after:today',
            ],
            'work_permit_issued_by' => [
                Rule::requiredIf(fn (): bool => $this->requiresWorkPermitDetailsForCurrentPayload()),
                'nullable',
                'string',
                'max:255',
            ],
            'residence_permit_type' => ['nullable', Rule::in(['unlimited', 'limited', 'none'])],
            'residence_permit_number' => ['nullable', 'string', 'max:255'],
            'residence_permit_expiry' => ['nullable', 'date'],

            // Criminal Record
            'criminal_record_status' => ['nullable', Rule::in(['valid', 'expired', 'pending'])],
            'criminal_record_check_date' => ['nullable', 'date'],

            // Organizational - Security: Validate user has access to selected unit
            'organizational_unit_id' => [
                'required',
                Rule::exists('organizational_units', 'id')->where(function (\Illuminate\Database\Query\Builder $query): void {
                    /** @var string $tenantId */
                    $tenantId = $this->input('tenant_id');
                    $query->where('tenant_id', $tenantId);
                }),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    /** @var User $user */
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
        ], $this->employeeAddressItemRules(), $this->certificationValidationRules());
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge([
            // Basic fields
            'first_name.required' => __('First name is required'),
            'last_name.required' => __('Last name is required'),
            'date_of_birth.required' => __('Date of birth is required'),
            'email.required' => __('Email address is required'),
            'email.email' => __('Email address must be valid'),
            'email.unique' => __('Email address is already in use'),
            'position.required' => __('Position is required'),
            'contract_type.required' => __('Contract type is required'),
            'contract_start_date.required' => __('Contract start date is required'),
            'status.required' => __('Employment status is required'),
            'status.in' => __('Valid employee statuses are: :statuses.', [
                'statuses' => implode(', ', Employee::VALID_STATUSES),
            ]),
            'organizational_unit_id.required' => __('Organizational unit is required'),
            'send_invitation.boolean' => __('Invitation sending must be true or false'),
            'bwr_status.missing' => __('BWR fields must be changed via the dedicated BWR status endpoint.'),
            'bwr_registered_at.missing' => __('BWR fields must be changed via the dedicated BWR status endpoint.'),
            'employment_end_date.missing' => __('Retention fields are managed by the employee lifecycle and cannot be written directly.'),
            'retention_period_end.missing' => __('Retention fields are managed by the employee lifecycle and cannot be written directly.'),
            'termination_date.after_or_equal' => __('Termination date must be after or equal to contract start date'),

            // BWR-ID validation
            'bwr_id.size' => 'Die Bewacher-ID muss exakt 7 Ziffern haben.',
            'bwr_id.regex' => 'Die Bewacher-ID darf nur Ziffern enthalten (0000000-9999999).',
            'bwr_id.unique' => 'Diese Bewacher-ID ist bereits vergeben.',

            // Gender (mandatory for BWR)
            'gender.required_if' => 'Geschlecht ist für BWR-Anmeldung verpflichtend.',

            // ISO country codes
            'birth_country.size' => 'Geburtsland muss ISO-Code mit 2 Buchstaben sein (z.B. DE, PL).',
            'birth_country.regex' => 'Geburtsland muss aus 2 Großbuchstaben bestehen.',
            'addresses.*.country.size' => 'Land muss ISO-Code mit 2 Buchstaben sein (z.B. DE, PL).',
            'addresses.*.country.regex' => 'Land muss aus 2 Großbuchstaben bestehen.',
            'nationalities.*.size' => 'Staatsangehörigkeit muss ISO-Code mit 2 Buchstaben sein (z.B. DE, TR).',
            'nationalities.*.regex' => 'Staatsangehörigkeit muss aus 2 Großbuchstaben bestehen.',

            // Emergency contacts
            'emergency_contacts.*.name.required' => 'Name für Notfallkontakt ist erforderlich.',
            'emergency_contacts.*.phone.required' => 'Telefonnummer für Notfallkontakt ist erforderlich.',
            'emergency_contacts.*.email.email' => 'E-Mail für Notfallkontakt muss gültig sein.',

            // Work permits
            'work_permit_type.required' => 'Arbeitserlaubnis-Typ ist für nicht freizügigkeitsberechtigte Staatsangehörigkeiten verpflichtend.',
            'work_permit_type.in' => 'Arbeitserlaubnis-Typ ist ungültig.',
            'work_permit_number.required' => 'Nummer der Arbeitserlaubnis ist verpflichtend.',
            'work_permit_issued_by.required' => 'Ausstellende Behörde der Arbeitserlaubnis ist verpflichtend.',
            'work_permit_expiry.required' => 'Ablaufdatum der Arbeitserlaubnis ist für befristete Arbeitserlaubnisse verpflichtend.',
            'work_permit_expiry.after' => 'Ablaufdatum der Arbeitserlaubnis muss in der Zukunft liegen.',
        ], $this->certificationValidationMessages());
    }

    private function validateEmployeeScopeConstraints(Validator $validator): void
    {
        /** @var User $user */
        $user = $this->user();

        if (! $user->organizationalScopes()->exists()) {
            $validator->errors()->add('organizational_unit_id', __('You must have an organizational scope before creating employees.'));

            return;
        }

        $organizationalUnitId = $this->input('organizational_unit_id');
        if (! is_string($organizationalUnitId) || $organizationalUnitId === '') {
            return;
        }

        $organizationalUnit = OrganizationalUnit::query()->find($organizationalUnitId);
        if (! $organizationalUnit instanceof OrganizationalUnit) {
            return;
        }

        $scopes = $user->getApplicableOrganizationalScopesForUnit($organizationalUnit)
            ->filter(fn ($scope): bool => $scope->hasMinimumAccessLevel('write'))
            ->values();

        if ($scopes->isEmpty()) {
            $validator->errors()->add('organizational_unit_id', __('You do not have write access to the selected organizational unit.'));

            return;
        }

        $managementLevel = $this->resolvedManagementLevel();
        $policy = app(EmployeePolicy::class);

        if (! $policy->canCreateInUnit($user, $organizationalUnit, $managementLevel)) {
            $validator->errors()->add(
                'management_level',
                __('You may only create employees whose management level remains assignable and viewable within your organizational scope.'),
            );
        }
    }

    private function resolvedManagementLevel(): int
    {
        $managementLevel = $this->input('management_level');

        return is_numeric($managementLevel) ? (int) $managementLevel : 0;
    }
}
