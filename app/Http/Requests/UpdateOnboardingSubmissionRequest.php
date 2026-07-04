<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests;

use App\Models\OnboardingFormSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateOnboardingSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var OnboardingFormSubmission|null $submission */
        $submission = $this->route('submission');

        return $submission !== null
            && ($this->user()?->can('update', $submission) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'form_data' => ['sometimes', 'required_without:status', 'array'],
            'status' => ['sometimes', 'required_without:form_data', 'in:draft,submitted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasAny(['form_data', 'status'])) {
                $validator->errors()->add('form_data', 'The form data field is required when status is not present.');
                $validator->errors()->add('status', 'The status field is required when form data is not present.');
            }
        });
    }
}
