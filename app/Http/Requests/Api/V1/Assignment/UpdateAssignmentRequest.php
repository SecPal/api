<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Assignment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for updating assignments (customer or site).
 *
 * Validates updates to existing role assignments. All fields are optional
 * (PATCH semantics). Used for both CustomerAssignment and SiteAssignment updates.
 *
 * @see \App\Models\CustomerAssignment
 * @see \App\Models\SiteAssignment
 * @see SecPal/api#315 Assignment API endpoints
 */
class UpdateAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled in the controller via policies.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'string', 'max:100'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom attribute names for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'role' => 'role',
            'valid_from' => 'valid from date',
            'valid_until' => 'valid until date',
            'notes' => 'notes',
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
            'role.max' => 'The role must not exceed 100 characters.',
            'valid_from.date' => 'The valid from date must be a valid date.',
            'valid_until.date' => 'The valid until date must be a valid date.',
            'valid_until.after_or_equal' => 'The valid until date must be on or after the valid from date.',
            'notes.max' => 'The notes must not exceed 1000 characters.',
        ];
    }
}
