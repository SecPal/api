<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request for storing SecretAttachment.
 *
 * Validates file uploads for secret attachments.
 */
class StoreSecretAttachmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled by SecretAttachmentPolicy, not here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var int $maxSize */
        $maxSize = config('attachments.max_file_size');
        /** @var array<int, string> $allowedMimes */
        $allowedMimes = config('attachments.allowed_mime_types');

        return [
            'file' => [
                'required',
                'file',
                'max:'.($maxSize / 1024), // Convert bytes to KB for Laravel validation
                'mimes:'.implode(',', array_map(
                    fn (string $mime): string => str_replace('application/', '', $mime),
                    $allowedMimes
                )),
            ],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        /** @var int $maxSizeBytes */
        $maxSizeBytes = config('attachments.max_file_size');
        $maxSize = $maxSizeBytes / (1024 * 1024); // Convert bytes to MB

        return [
            'file.required' => 'Please select a file to upload.',
            'file.file' => 'The uploaded file is not valid.',
            'file.max' => 'The file size must not exceed '.$maxSize.' MB.',
            'file.mimes' => 'The file type is not allowed. Only certain file types are permitted.',
        ];
    }
}
