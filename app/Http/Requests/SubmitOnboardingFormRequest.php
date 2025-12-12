<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SubmitOnboardingFormRequest validates onboarding form submission requests.
 *
 * Form data structure is validated against the form template schema at controller level.
 */
class SubmitOnboardingFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by OnboardingFormSubmissionPolicy
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'form_template_id' => ['required', 'exists:onboarding_form_templates,id'],
            'form_data' => ['required', 'array'],
            'status' => ['required', 'in:draft,submitted'],
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
            'form_template_id.required' => __('Form template is required'),
            'form_template_id.exists' => __('Selected form template does not exist'),
            'form_data.required' => __('Form data is required'),
            'form_data.array' => __('Form data must be an array'),
        ];
    }
}
