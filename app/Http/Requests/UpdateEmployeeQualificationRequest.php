<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateEmployeeQualificationRequest validates updates to EmployeeQualification pivot records.
 *
 * All fields are optional (PATCH semantics).
 */
class UpdateEmployeeQualificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by EmployeeQualificationPolicy
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
            'obtained_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'expiry_date' => ['sometimes', 'nullable', 'date', 'after:obtained_date'],
            'certificate_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issuing_authority' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'document_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::in(['valid', 'expiring_soon', 'expired'])],
        ];
    }
}
