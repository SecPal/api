<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for updating an organizational scope assignment.
 */
class UpdateOrganizationalScopeRequest extends FormRequest
{
    /**
     * Valid access levels for scope assignments.
     *
     * @var array<string>
     */
    private const VALID_ACCESS_LEVELS = ['none', 'read', 'write', 'manage', 'admin'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by controller/policy
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'access_level' => [
                'sometimes',
                'string',
                Rule::in(self::VALID_ACCESS_LEVELS),
            ],
            'include_descendants' => ['sometimes', 'boolean'],
            // Leadership-based access control fields (ADR-009)
            'min_viewable_rank' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255', 'lte:max_viewable_rank'],
            'max_viewable_rank' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255', 'gte:min_viewable_rank'],
            'min_assignable_rank' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255', 'lte:max_assignable_rank'],
            'max_assignable_rank' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255', 'gte:min_assignable_rank'],
            'allow_self_access' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'access_level.in' => 'The access level must be one of: none, read, write, manage, admin.',
        ];
    }
}
