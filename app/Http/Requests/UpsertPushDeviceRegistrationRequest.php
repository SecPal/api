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

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        $this->setNestedInteger($payload, ['app', 'package_version_code']);
        $this->setNestedInteger($payload, ['device', 'sdk_int']);
        $this->setNestedInteger($payload, ['runtime', 'schema_version']);
        $this->setNestedInteger($payload, ['runtime', 'push_metadata_revision']);

        $this->replace($payload);
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $path
     */
    private function setNestedInteger(array &$payload, array $path): void
    {
        $target = &$payload;
        $lastIndex = array_key_last($path);

        foreach ($path as $index => $segment) {
            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                return;
            }

            if ($index === $lastIndex) {
                if (is_string($target[$segment]) && filter_var($target[$segment], FILTER_VALIDATE_INT) !== false) {
                    $target[$segment] = (int) $target[$segment];
                }

                return;
            }

            $target = &$target[$segment];
        }
    }
}
