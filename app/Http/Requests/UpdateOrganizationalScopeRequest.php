<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\OrganizationalUnit;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            ],
            'max_viewable_rank' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:255',
                'gte:min_viewable_rank',
            ],
            'min_assignable_rank' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:255',
                'lte:max_assignable_rank',
            ],
            'max_assignable_rank' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:255',
                'gte:min_assignable_rank',
            ],
            'allow_self_access' => ['sometimes', 'boolean'],
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
            'access_level.in' => 'The access level must be one of: none, read, write, manage, admin.',
        ];
    }

    private function validateLeadershipRangePair(Validator $validator, string $minimumField, string $maximumField): void
    {
        $minimum = $this->effectiveIntegerValue($minimumField);
        $maximum = $this->effectiveIntegerValue($maximumField);

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

    private function effectiveIntegerValue(string $field): ?int
    {
        if ($this->hasRequestField($field)) {
            $value = $this->input($field);

            if ($value === null || $value === '') {
                return null;
            }

            return is_numeric($value) ? (int) $value : null;
        }

        $scope = $this->currentScope();

        if ($scope === null) {
            return null;
        }

        /** @var int|null $existingValue */
        $existingValue = $scope->getAttribute($field);

        return $existingValue;
    }

    private function currentScope(): ?UserInternalOrganizationalScope
    {
        $scope = $this->route('scope');

        if ($scope instanceof UserInternalOrganizationalScope) {
            return $scope;
        }

        return is_string($scope) ? UserInternalOrganizationalScope::find($scope) : null;
    }
}
