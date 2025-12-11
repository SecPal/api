<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreEmployeeRequest validates Employee creation requests.
 *
 * All personal data fields will be encrypted by the Employee model mutators.
 */
class StoreEmployeeRequest extends FormRequest
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
        return [
            // Personal Data (will be encrypted)
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'email' => ['required', 'email', 'unique:employees,email'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'photo_path' => ['nullable', 'string', 'max:255'],

            // Tax & Social Security (will be encrypted)
            'tax_id' => ['nullable', 'string', 'max:255'],
            'social_security_number' => ['nullable', 'string', 'max:255'],

            // Employment Status
            'status' => ['required', Rule::in([
                Employee::STATUS_APPLICANT,
                Employee::STATUS_PRE_CONTRACT,
                Employee::STATUS_ACTIVE,
                Employee::STATUS_ON_LEAVE,
                Employee::STATUS_TERMINATED,
            ])],
            'hire_date' => ['nullable', 'date'],
            'contract_start_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date', 'after_or_equal:contract_start_date'],
            'last_working_day' => ['nullable', 'date'],

            // Contract Details
            'contract_type' => ['required', Rule::in(['full_time', 'part_time', 'minijob', 'freelance'])],
            'weekly_hours' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'monthly_hours' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],

            // Health Insurance
            'health_insurance_type' => ['nullable', Rule::in(['public', 'private', 'foreign'])],
            'health_insurance_provider' => ['nullable', 'string', 'max:255'],
            'health_insurance_number' => ['nullable', 'string', 'max:255'],

            // Legal Requirements (BewachV)
            'sachkunde_type' => ['nullable', 'string', 'max:255'],
            'sachkunde_certificate' => ['nullable', 'string', 'max:255'],
            'sachkunde_expiry' => ['nullable', 'date'],

            // Work & Residence Permits
            'work_permit_type' => ['nullable', Rule::in(['unlimited', 'limited', 'none'])],
            'work_permit_number' => ['nullable', 'string', 'max:255'],
            'work_permit_expiry' => ['nullable', 'date'],
            'residence_permit_type' => ['nullable', Rule::in(['unlimited', 'limited', 'none'])],
            'residence_permit_number' => ['nullable', 'string', 'max:255'],
            'residence_permit_expiry' => ['nullable', 'date'],

            // Criminal Record
            'criminal_record_status' => ['nullable', Rule::in(['valid', 'expired', 'pending'])],
            'criminal_record_check_date' => ['nullable', 'date'],

            // Organizational
            'organizational_unit_id' => ['nullable', 'exists:organizational_units,id'],
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
            'first_name.required' => __('First name is required'),
            'last_name.required' => __('Last name is required'),
            'email.required' => __('Email address is required'),
            'email.email' => __('Email address must be valid'),
            'email.unique' => __('Email address is already in use'),
            'contract_type.required' => __('Contract type is required'),
            'status.required' => __('Employment status is required'),
            'termination_date.after_or_equal' => __('Termination date must be after or equal to contract start date'),
        ];
    }
}
