<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\Qualification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreQualificationRequest validates Qualification creation requests.
 *
 * Used for creating custom tenant-specific qualifications.
 * System qualifications are seeded and cannot be created via API.
 */
class StoreQualificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Qualification::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['required', Rule::in([
                'bewachv_34a',
                'first_aid',
                'fire_safety',
                'safety_officer',
                'specialized',
                'education',
                'custom',
            ])],
            'requires_renewal' => ['required', 'boolean'],
            'renewal_period_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_mandatory' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
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
            'name.required' => __('Qualification name is required'),
            'category.required' => __('Category is required'),
            'requires_renewal.required' => __('Renewal requirement must be specified'),
            'is_mandatory.required' => __('Mandatory status must be specified'),
        ];
    }
}
