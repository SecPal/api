<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request validation for activity log index filtering.
 *
 * Supports filtering by:
 * - Date range (from_date, to_date)
 * - Log name (log_name)
 * - Search (description)
 * - Organizational unit (organizational_unit_id)
 * - Causer type and ID
 * - Subject type and ID
 *
 * Authorization handled by ActivityPolicy.
 *
 * @see \App\Http\Controllers\Api\V1\ActivityLogController
 * @see \App\Policies\ActivityPolicy
 */
class IndexActivityLogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization handled by ActivityPolicy in controller
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Date range filtering
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],

            // Log name filtering (exact match)
            'log_name' => ['nullable', 'string', 'max:255'],

            // Search in description (case-insensitive)
            'search' => ['nullable', 'string', 'max:255'],

            // Organizational unit filtering (tenant-scoped)
            'organizational_unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('organizational_units', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],

            // Causer filtering
            'causer_type' => ['nullable', 'string', 'max:255'],
            'causer_id' => ['nullable', 'uuid'],

            // Subject filtering
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'uuid'],

            // Pagination
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],

            // Include verification data
            'include_verification' => ['nullable', 'boolean'],
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
            'to_date.after_or_equal' => 'End date must be on or after start date.',
            'organizational_unit_id.exists' => 'The selected organizational unit does not exist.',
            'per_page.max' => 'Maximum 100 items per page.',
        ];
    }
}
