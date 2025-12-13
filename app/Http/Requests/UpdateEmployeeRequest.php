<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
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
        // Authorization is handled by EmployeePolicy
        return true;
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
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Tax & Social Security (will be encrypted)
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'social_security_number' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Employment Status
            'status' => ['sometimes', 'required', Rule::in([
                Employee::STATUS_APPLICANT,
                Employee::STATUS_PRE_CONTRACT,
                Employee::STATUS_ACTIVE,
                Employee::STATUS_ON_LEAVE,
                Employee::STATUS_TERMINATED,
            ])],
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

            // Legal Requirements (BewachV)
            'sachkunde_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sachkunde_certificate' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sachkunde_expiry' => ['sometimes', 'nullable', 'date'],

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
                Rule::exists('organizational_units', 'id')->where('tenant_id', $this->input('tenant_id')),
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
            'termination_date.after_or_equal' => __('Termination date must be after or equal to contract start date'),
        ];
    }
}
