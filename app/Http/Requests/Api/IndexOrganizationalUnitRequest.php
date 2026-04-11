<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api;

use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Http\FormRequest;

class IndexOrganizationalUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', OrganizationalUnit::class) ?? false;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'type' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'string'],
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
