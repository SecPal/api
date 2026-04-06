<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExchangeAndroidBootstrapTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'bootstrap_token' => ['required', 'string', 'min:32', 'max:255'],
            'package_name' => ['required', 'in:app.secpal'],
            'package_version_name' => ['nullable', 'string', 'max:50'],
            'package_version_code' => ['nullable', 'integer', 'min:1'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'device' => ['nullable', 'array'],
            'device.manufacturer' => ['nullable', 'string', 'max:120'],
            'device.model' => ['nullable', 'string', 'max:120'],
            'device.android_version' => ['nullable', 'string', 'max:30'],
        ];
    }
}
