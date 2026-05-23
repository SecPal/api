<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\BootstrapContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertPushDeviceRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', Rule::in(['android'])],
            'provider' => ['required', 'string', Rule::in([BootstrapContract::ANDROID_PUSH_PROVIDER])],
            'device_name' => ['required', 'string', 'max:120'],
            'push_token' => ['required', 'string', 'min:32', 'max:4096'],
            'lifecycle_event' => ['required', 'string', Rule::in(['registered', 'token_rotated', 'app_updated'])],
            'app' => ['required', 'array'],
            'app.package_name' => ['required', 'string', Rule::in(['app.secpal'])],
            'app.package_version_name' => ['nullable', 'string', 'max:50'],
            'app.package_version_code' => ['nullable', 'integer', 'min:1'],
            'device' => ['sometimes', 'array'],
            'device.manufacturer' => ['nullable', 'string', 'max:120'],
            'device.model' => ['nullable', 'string', 'max:120'],
            'device.android_version' => ['nullable', 'string', 'max:30'],
            'device.sdk_int' => ['nullable', 'integer', 'min:21'],
            'runtime' => ['required', 'array'],
            'runtime.bootstrap_version' => ['required', 'string', Rule::in([BootstrapContract::VERSION])],
            'runtime.schema_version' => ['required', 'integer', Rule::in([BootstrapContract::SCHEMA_VERSION])],
            'runtime.push_metadata_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
