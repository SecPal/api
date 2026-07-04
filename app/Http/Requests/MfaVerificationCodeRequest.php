<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MfaVerificationCodeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize method and code input before validating.
     */
    protected function prepareForValidation(): void
    {
        $method = $this->input('method');
        $code = $this->input('code');

        if (! is_string($method) || ! is_string($code)) {
            return;
        }

        $normalizedMethod = strtolower(trim($method));
        $normalizedCode = trim($code);

        if ($normalizedMethod === 'recovery_code') {
            $normalizedCode = strtoupper((string) preg_replace('/[\s-]+/', '', $normalizedCode));
        }

        $this->merge([
            'method' => $normalizedMethod,
            'code' => $normalizedCode,
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
            'method' => [
                'required',
                Rule::in(['totp', 'recovery_code']),
            ],
            'code' => ['required', 'string', 'max:255'],
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
            'method.required' => 'Verification method is required.',
            'method.in' => 'Verification method must be either totp or recovery_code.',
            'code.required' => 'Verification code is required.',
        ];
    }
}
