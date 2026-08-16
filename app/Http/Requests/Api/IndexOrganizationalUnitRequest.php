<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api;

use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IndexOrganizationalUnitRequest extends FormRequest
{
    private const BOOLEAN_QUERY_PATTERN = '/\\A(?:0|1|true|false)\\z/';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', OrganizationalUnit::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'type' => ['nullable', 'string', Rule::in(['holding', 'company', 'region', 'branch', 'division', 'department', 'custom'])],
            'is_active' => ['nullable', 'string', 'regex:'.self::BOOLEAN_QUERY_PATTERN],
            'is_assignable' => ['nullable', 'string', 'regex:'.self::BOOLEAN_QUERY_PATTERN],
            'parent_id' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === 'null' || (is_string($value) && Str::isUuid($value))) {
                        return;
                    }

                    $fail('The '.$attribute.' field must be a valid UUID or "null".');
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.max' => 'Maximum 100 items per page.',
        ];
    }
}
