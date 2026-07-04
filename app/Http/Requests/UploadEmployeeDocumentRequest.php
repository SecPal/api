<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UploadEmployeeDocumentRequest validates employee document upload requests.
 *
 * Handles file validation and document metadata.
 */
class UploadEmployeeDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Employee|null $employee */
        $employee = $this->route('employee');

        return $employee !== null
            && ($this->user()?->can('create', [EmployeeDocument::class, $employee]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['bail', 'required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'], // 10MB max
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'document_type' => ['required', Rule::in([
                'contract',
                'id_copy',
                'criminal_record',
                'qualification',
                'reference',
                'health_certificate',
                'work_permit',
                'other',
            ])],
            'expiry_date' => ['nullable', 'date', 'after:today'],
            'visible_to_employee' => ['required', 'boolean'],
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
            'file.required' => __('File is required'),
            'file.max' => __('File must not exceed 10MB'),
            'file.mimes' => __('File must be PDF, JPG, JPEG, or PNG'),
            'file.mimetypes' => __('File must be PDF, JPG, JPEG, or PNG'),
            'title.required' => __('Document title is required'),
            'document_type.required' => __('Document type is required'),
            'visible_to_employee.required' => __('Visibility setting is required'),
        ];
    }
}
