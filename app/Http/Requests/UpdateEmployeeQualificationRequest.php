<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\EmployeeQualification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateEmployeeQualificationRequest validates updates to EmployeeQualification pivot records.
 *
 * All fields are optional (PATCH semantics).
 */
class UpdateEmployeeQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var EmployeeQualification|null $employeeQualification */
        $employeeQualification = $this->route('employeeQualification');

        return $employeeQualification !== null
            && ($this->user()?->can('update', $employeeQualification) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'obtained_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'expiry_date' => ['sometimes', 'nullable', 'date', 'after:obtained_date'],
            'certificate_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issuing_authority' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'nullable', Rule::in(['valid', 'expiring_soon', 'expired'])],
        ];
    }
}
