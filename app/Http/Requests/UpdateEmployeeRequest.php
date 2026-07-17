<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests;

use App\Http\Requests\Concerns\InteractsWithCertificationValidation;
use App\Http\Requests\Concerns\InteractsWithEmployeeAddressValidation;
use App\Http\Requests\Concerns\InteractsWithEmployeeDomainValidation;
use App\Http\Requests\Concerns\InteractsWithWorkPermitValidation;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * UpdateEmployeeRequest validates Employee update requests.
 *
 * All fields are optional (PATCH semantics).
 * Email uniqueness excludes the current employee.
 */
class UpdateEmployeeRequest extends FormRequest
{
    use InteractsWithCertificationValidation;
    use InteractsWithEmployeeAddressValidation;
    use InteractsWithEmployeeDomainValidation;
    use InteractsWithWorkPermitValidation;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Employee|null $employee */
        $employee = $this->route('employee');

        return $employee !== null && ($this->user()?->can('update', $employee) ?? false);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateSalaryWriteAccess($validator);
            /** @var Employee|null $employee */
            $employee = $this->route('employee');
            $this->validateEmployeeDomainAssignment($validator, $employee);
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
        /** @var Employee|null $employee */
        $employee = $this->route('employee');
        $employeeId = $this->route('employee');

        return array_merge([
            // Personal Data (will be encrypted)
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'email' => ['sometimes', 'required', 'email', Rule::unique('employees', 'email')->ignore($employeeId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:255'],

            // BewachV § 16 Abs. 2 Nr. 1: BWR Tracking
            'bwr_id' => ['missing'],
            'bwr_status' => ['missing'],
            'bwr_registered_at' => ['missing'],
            'bwr_submission_date' => ['sometimes', 'nullable', 'date'],
            'bwr_notes' => ['missing'],

            // BewachV § 16 Abs. 2 Nr. 2: Identity Data
            'gender' => ['sometimes', 'nullable', Rule::in(['male', 'female', 'diverse'])],
            'birth_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'previous_names' => ['sometimes', 'nullable', 'array'],
            'previous_names.*' => ['string', 'max:255'],

            // BewachV § 16 Abs. 2 Nr. 3: Birth Place
            'birth_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birth_country' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],

            // BewachV § 16 Abs. 2 Nr. 4: Nationalities
            'nationalities' => ['sometimes', 'nullable', 'array'],
            'nationalities.*' => ['string', 'size:2', 'regex:/^[A-Z]{2}$/'],

            'addresses' => ['sometimes', 'nullable', 'array'],

            // Emergency contacts
            'emergency_contacts' => ['sometimes', 'nullable', 'array'],
            'emergency_contacts.*.name' => ['required', 'string', 'max:255'],
            'emergency_contacts.*.relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contacts.*.phone' => ['required', 'string', 'max:255'],
            'emergency_contacts.*.email' => ['nullable', 'email', 'max:255'],
            'emergency_contacts.*.notes' => ['nullable', 'string', 'max:500'],

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
            'employment_end_date' => ['missing'],
            'retention_period_end' => ['missing'],

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
            'work_permit_type' => [
                Rule::requiredIf(fn (): bool => $this->touchesWorkPermitContext() && $this->requiresWorkPermitForCurrentPayload() && $this->mergedWorkPermitTypeIsNoneOrBlank()),
                'nullable',
                Rule::in(Employee::VALID_WORK_PERMIT_TYPES),
            ],
            'work_permit_number' => [
                Rule::requiredIf(fn (): bool => $this->touchesWorkPermitContext() && $this->requiresWorkPermitDetailsForCurrentPayload() && $this->mergedValueIsBlank('work_permit_number')),
                'nullable',
                'string',
                'max:255',
            ],
            'work_permit_expiry' => [
                Rule::requiredIf(fn (): bool => $this->touchesWorkPermitContext() && $this->requiresWorkPermitExpiryForCurrentPayload() && $this->mergedEmployeeValue('work_permit_expiry') === null),
                'nullable',
                'date',
                'after:today',
            ],
            'work_permit_issued_by' => [
                Rule::requiredIf(fn (): bool => $this->touchesWorkPermitContext() && $this->requiresWorkPermitDetailsForCurrentPayload() && $this->mergedValueIsBlank('work_permit_issued_by')),
                'nullable',
                'string',
                'max:255',
            ],
            'residence_permit_type' => ['sometimes', 'nullable', Rule::in(['unlimited', 'limited', 'none'])],
            'residence_permit_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'residence_permit_expiry' => ['sometimes', 'nullable', 'date'],

            // Criminal Record
            'criminal_record_status' => ['sometimes', 'nullable', Rule::in(['valid', 'expired', 'pending'])],
            'criminal_record_check_date' => ['sometimes', 'nullable', 'date'],

            'legal_entity_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('legal_entities', 'id')->where(function (\Illuminate\Database\Query\Builder $query): void {
                    /** @var string $tenantId */
                    $tenantId = $this->input('tenant_id');
                    $query->where('tenant_id', $tenantId);
                })->whereNull('deleted_at'),
            ],
            'establishment_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('establishments', 'id')->where(function (\Illuminate\Database\Query\Builder $query) use ($employee): void {
                    /** @var string $tenantId */
                    $tenantId = $this->input('tenant_id');
                    $legalEntityId = $this->input('legal_entity_id', $employee?->legal_entity_id);
                    $query->where('tenant_id', $tenantId)
                        ->where('legal_entity_id', $legalEntityId);
                })->whereNull('deleted_at'),
            ],
            'organizational_unit_id' => ['prohibited'],
        ], $this->employeeAddressItemRules(), $this->certificationValidationRules(true));
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge([
            'email.email' => __('Email address must be valid'),
            'email.unique' => __('Email address is already in use'),
            'status.missing' => __('Employee status transitions must use the dedicated activate, leave, return-from-leave, or terminate endpoints.'),
            'bwr_id.missing' => __('BWR fields must be changed via the dedicated BWR status endpoint.'),
            'bwr_status.missing' => __('BWR fields must be changed via the dedicated BWR status endpoint.'),
            'bwr_registered_at.missing' => __('BWR fields must be changed via the dedicated BWR status endpoint.'),
            'bwr_notes.missing' => __('BWR fields must be changed via the dedicated BWR status endpoint.'),
            'employment_end_date.missing' => __('Retention fields are managed by the employee lifecycle and cannot be written directly.'),
            'retention_period_end.missing' => __('Retention fields are managed by the employee lifecycle and cannot be written directly.'),
            'termination_date.after_or_equal' => __('Termination date must be after or equal to contract start date'),

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

    private function touchesWorkPermitContext(): bool
    {
        foreach (['nationalities', 'work_permit_type', 'work_permit_number', 'work_permit_expiry', 'work_permit_issued_by'] as $field) {
            if ($this->exists($field)) {
                return true;
            }
        }

        return false;
    }

    private function validateSalaryWriteAccess(Validator $validator): void
    {
        if (! $this->exists('hourly_rate')) {
            return;
        }

        if ($this->user()?->can('employees.read_salary') ?? false) {
            return;
        }

        $validator->errors()->add('hourly_rate', __('You are not authorized to manage salary data.'));
    }
}
