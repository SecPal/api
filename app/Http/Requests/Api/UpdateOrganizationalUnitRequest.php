<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests\Api;

use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for updating an organizational unit.
 */
class UpdateOrganizationalUnitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var OrganizationalUnit|null $organizationalUnit */
        $organizationalUnit = $this->route('organizational_unit');

        return $organizationalUnit !== null
            && ($this->user()?->can('update', $organizationalUnit) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(['holding', 'company', 'region', 'branch', 'division', 'department', 'custom'])],
            'custom_type_name' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(function (): bool {
                    /** @var OrganizationalUnit|null $organizationalUnit */
                    $organizationalUnit = $this->route('organizational_unit');
                    $effectiveType = $this->input('type', $organizationalUnit?->type);

                    return $effectiveType === 'custom'
                        && ($this->has('type') || $this->has('custom_type_name'));
                }),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
            'is_legal_entity' => ['sometimes', $this->strictBooleanRule()],
            'is_establishment' => ['sometimes', $this->strictBooleanRule()],
        ];
    }

    /**
     * Get custom validation messages for the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custom_type_name.required' => 'The custom type name is required when type is "custom".',
        ];
    }

    private function strictBooleanRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_bool($value)) {
                $fail("The {$attribute} field must be a JSON boolean (true or false).");
            }
        };
    }
}
