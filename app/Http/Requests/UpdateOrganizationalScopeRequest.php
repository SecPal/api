<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\OrganizationalUnit;
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
        /** @var OrganizationalUnit|null $organizationalUnit */
        $organizationalUnit = $this->route('organizational_unit');

        return $organizationalUnit !== null
            && ($this->user()?->can('manageScopes', $organizationalUnit) ?? false);
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
            'min_viewable_rank' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:255',
                'lte:max_viewable_rank',
                function ($attribute, $value, $fail) {
                    $maxRank = $this->input('max_viewable_rank');
                    // Prevent mixing Guards (min=0) with Leadership (max>0)
                    if ($value === 0 && $maxRank !== null && $maxRank > 0) {
                        $fail('Guards (min=0) and Leadership (max>0) must use separate scopes. Use min=0, max=0 for Guards only.');
                    }
                },
            ],
            'max_viewable_rank' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:255',
                'gte:min_viewable_rank',
                function ($attribute, $value, $fail) {
                    $minRank = $this->input('min_viewable_rank');
                    // Prevent mixing Guards (min=0) with Leadership (max>0)
                    if ($minRank === 0 && $value !== null && $value > 0) {
                        $fail('Guards (min=0) and Leadership (max>0) must use separate scopes. Use min=0, max=0 for Guards only.');
                    }
                },
            ],
            'min_assignable_rank' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:255',
                'lte:max_assignable_rank',
                function ($attribute, $value, $fail) {
                    $maxRank = $this->input('max_assignable_rank');
                    // Prevent mixing Guards (min=0) with Leadership (max>0)
                    if ($value === 0 && $maxRank !== null && $maxRank > 0) {
                        $fail('Guards (min=0) and Leadership (max>0) must use separate scopes. Use min=0, max=0 for Guards only.');
                    }
                },
            ],
            'max_assignable_rank' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:255',
                'gte:min_assignable_rank',
                function ($attribute, $value, $fail) {
                    $minRank = $this->input('min_assignable_rank');
                    // Prevent mixing Guards (min=0) with Leadership (max>0)
                    if ($minRank === 0 && $value !== null && $value > 0) {
                        $fail('Guards (min=0) and Leadership (max>0) must use separate scopes. Use min=0, max=0 for Guards only.');
                    }
                },
            ],
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
