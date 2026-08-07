<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * IndexEmployeeRequest validates employee list filters.
 */
class IndexEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Employee::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(Employee::VALID_STATUSES)],
            'compliance_status' => ['missing'],
            'legal_entity_id' => [
                'nullable',
                'uuid',
            ],
            'establishment_id' => [
                'nullable',
                'uuid',
            ],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
            'status.in' => __('Status filter must be one of: :statuses.', [
                'statuses' => implode(', ', Employee::VALID_STATUSES),
            ]),
            'compliance_status.missing' => __('Compliance status filtering is only supported by the compliance alerts endpoint.'),
        ];
    }
}
