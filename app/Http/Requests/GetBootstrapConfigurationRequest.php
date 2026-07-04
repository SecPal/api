<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\BootstrapContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GetBootstrapConfigurationRequest extends FormRequest
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
            'client_platform' => ['required', Rule::in([
                BootstrapContract::CLIENT_PLATFORM_ANDROID,
                BootstrapContract::CLIENT_PLATFORM_BROWSER,
            ])],
            'app_version' => [
                Rule::requiredIf(fn (): bool => $this->isAndroidClientRequest()),
                'nullable',
                'string',
                'max:50',
                'regex:/^\d+\.\d+\.\d+$/',
            ],
            'app_build' => [
                Rule::requiredIf(fn (): bool => $this->isAndroidClientRequest()),
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    public function clientPlatform(): string
    {
        return $this->string('client_platform')->toString();
    }

    public function appVersion(): ?string
    {
        $appVersion = trim($this->string('app_version')->toString());

        return $appVersion === '' ? null : $appVersion;
    }

    public function appBuild(): ?int
    {
        if (! $this->has('app_build')) {
            return null;
        }

        return $this->integer('app_build');
    }

    private function isAndroidClientRequest(): bool
    {
        return $this->input('client_platform') === BootstrapContract::CLIENT_PLATFORM_ANDROID;
    }
}
