<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests;

use App\Models\Qualification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateQualificationRequest validates Qualification update requests.
 *
 * All fields are optional (PATCH semantics).
 * System qualifications cannot be updated via API.
 */
class UpdateQualificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Qualification|null $qualification */
        $qualification = $this->route('qualification');

        return $qualification !== null && ($this->user()?->can('update', $qualification) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category' => ['sometimes', 'required', Rule::in([
                'bewachv_34a',
                'first_aid',
                'fire_safety',
                'safety_officer',
                'specialized',
                'education',
                'custom',
            ])],
            'requires_renewal' => ['sometimes', 'required', 'boolean'],
            'renewal_period_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
            'is_mandatory' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
