<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasskeyAuthenticationVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.raw_id' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.client_extension_results' => ['sometimes', 'array'],
            'credential.response' => ['required', 'array'],
            'credential.response.client_data_json' => ['required', 'string'],
            'credential.response.authenticator_data' => ['required', 'string'],
            'credential.response.signature' => ['required', 'string'],
            'credential.response.user_handle' => ['nullable', 'string'],
        ];
    }
}
