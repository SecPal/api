<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests;

class TokenPasskeyAuthenticationChallengeRequest extends PasskeyAuthenticationChallengeRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $deviceName = $this->input('device_name');

        if (! is_string($deviceName)) {
            return;
        }

        $trimmedDeviceName = trim($deviceName);

        $this->merge([
            'device_name' => $trimmedDeviceName === '' ? null : $trimmedDeviceName,
        ]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'device_name.required' => 'Device name is required.',
            'device_name.max' => 'Device name must not exceed 255 characters.',
        ];
    }
}
