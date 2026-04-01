<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminResetUserMfaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the provided audit reason before validation.
     */
    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (! is_string($reason)) {
            return;
        }

        $this->merge([
            'reason' => trim($reason),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Reset reason is required.',
            'reason.max' => 'Reset reason must not exceed 500 characters.',
        ];
    }
}
