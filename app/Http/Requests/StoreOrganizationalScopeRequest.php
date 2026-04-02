<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation rules for creating a new organizational scope assignment.
 */
class StoreOrganizationalScopeRequest extends FormRequest
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
        /** @var OrganizationalUnit|null $organizationalUnit */
        $organizationalUnit = $this->route('organizational_unit');
        $organizationalUnitId = $organizationalUnit?->id;

        return [
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('users', 'id'),
                Rule::unique('user_internal_organizational_scopes')
                    ->where('organizational_unit_id', $organizationalUnitId),
            ],
            'access_level' => [
                'required',
                'string',
                Rule::in(self::VALID_ACCESS_LEVELS),
            ],
            'include_descendants' => ['boolean'],
            // Leadership-based access control fields (ADR-009)
            'min_viewable_rank' => [
                'nullable',
                'integer',
                'min:0',
                'max:255',
                'lte:max_viewable_rank',
            ],
            'max_viewable_rank' => [
                'nullable',
                'integer',
                'min:0',
                'max:255',
                'gte:min_viewable_rank',
            ],
            'min_assignable_rank' => [
                'nullable',
                'integer',
                'min:0',
                'max:255',
                'lte:max_assignable_rank',
            ],
            'max_assignable_rank' => [
                'nullable',
                'integer',
                'min:0',
                'max:255',
                'gte:min_assignable_rank',
            ],
            'allow_self_access' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateLeadershipRangePair($validator, 'min_viewable_rank', 'max_viewable_rank');
            $this->validateLeadershipRangePair($validator, 'min_assignable_rank', 'max_assignable_rank');
        });
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'A user ID is required.',
            'user_id.uuid' => 'The user ID must be a valid UUID.',
            'user_id.exists' => 'The specified user does not exist.',
            'user_id.unique' => 'This user already has a scope assignment for this organizational unit.',
            'access_level.required' => 'An access level is required.',
            'access_level.in' => 'The access level must be one of: none, read, write, manage, admin.',
        ];
    }

    private function validateLeadershipRangePair(Validator $validator, string $minimumField, string $maximumField): void
    {
        $minimum = $this->requestIntegerValue($minimumField);
        $maximum = $this->requestIntegerValue($maximumField);

        if ($maximum !== null && $maximum > 0 && ($minimum === null || $minimum <= 0)) {
            $validator->errors()->add(
                $this->hasRequestField($minimumField) ? $minimumField : $maximumField,
                'Leadership rank ranges require a minimum rank greater than 0 when the maximum rank is greater than 0. Use a separate guards-only scope instead.',
            );
        }
    }

    private function hasRequestField(string $field): bool
    {
        return array_key_exists($field, $this->all());
    }

    private function requestIntegerValue(string $field): ?int
    {
        if (! $this->hasRequestField($field)) {
            return null;
        }

        $value = $this->input($field);

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
