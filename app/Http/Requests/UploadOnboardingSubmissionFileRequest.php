<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\OnboardingFormSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadOnboardingSubmissionFileRequest extends FormRequest
{
    private const DEFAULT_MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    public function authorize(): bool
    {
        /** @var OnboardingFormSubmission|null $submission */
        $submission = $this->route('submission');

        return $submission !== null
            && ($this->user()?->can('uploadFile', $submission) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxKilobytes = $this->maxFileSizeKilobytes();

        return [
            'file' => ['bail', 'required', 'file', "max:{$maxKilobytes}", 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
            'idempotency_key' => ['nullable', 'string', 'min:32', 'max:64', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'document_type' => ['required', Rule::in([
                'contract',
                'id_document',
                'banking_details',
            ])],
            'document_subtype' => [
                Rule::requiredIf(fn (): bool => $this->input('document_type') === 'id_document'),
                'nullable',
                Rule::in([
                    'identity_document',
                    'identity_document_front',
                    'identity_document_back',
                    'proof_of_residence',
                    'residence_permit_front',
                    'residence_permit_back',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.uploaded' => __('The file upload failed before validation completed. Check PHP upload limits (upload_max_filesize: :upload_max, post_max_size: :post_max) or the temporary upload directory configuration.', [
                'upload_max' => $this->phpIniValue('upload_max_filesize'),
                'post_max' => $this->phpIniValue('post_max_size'),
            ]),
        ];
    }

    private function maxFileSizeKilobytes(): int
    {
        $maxFileSizeBytes = config('attachments.max_file_size', self::DEFAULT_MAX_FILE_SIZE_BYTES);

        if (! is_numeric($maxFileSizeBytes)) {
            return (int) ceil(self::DEFAULT_MAX_FILE_SIZE_BYTES / 1024);
        }

        $numeric = (float) $maxFileSizeBytes;
        if ($numeric <= 0) {
            return (int) ceil(self::DEFAULT_MAX_FILE_SIZE_BYTES / 1024);
        }

        return (int) ceil($numeric / 1024);
    }

    private function phpIniValue(string $key): string
    {
        $value = ini_get($key);

        return is_string($value) && $value !== '' ? $value : 'n/a';
    }
}
