<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\OnboardingFormSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadOnboardingSubmissionFileRequest extends FormRequest
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
            'file' => ['bail', 'required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
            'document_type' => ['required', Rule::in([
                'contract',
                'id_document',
                'banking_details',
            ])],
        ];
    }
}
