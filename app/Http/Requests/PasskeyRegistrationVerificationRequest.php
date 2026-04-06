<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasskeyRegistrationVerificationRequest extends FormRequest
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
            'label' => ['nullable', 'string', 'max:100'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.raw_id' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.client_extension_results' => ['sometimes', 'array'],
            'credential.response' => ['required', 'array'],
            'credential.response.client_data_json' => ['required', 'string'],
            'credential.response.attestation_object' => ['required', 'string'],
            'credential.response.transports' => ['sometimes', 'array'],
            'credential.response.transports.*' => ['string', 'in:ble,hybrid,internal,nfc,usb'],
        ];
    }
}
