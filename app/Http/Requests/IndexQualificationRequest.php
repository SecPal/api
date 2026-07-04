<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests;

use App\Models\Qualification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexQualificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Qualification::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_system_qualification' => ['nullable', 'boolean'],
            'category' => ['nullable', Rule::in([
                'bewachv_34a',
                'first_aid',
                'fire_safety',
                'safety_officer',
                'specialized',
                'education',
                'custom',
            ])],
            'is_mandatory' => ['nullable', 'boolean'],
        ];
    }
}
