<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests;

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
            'client_platform' => ['required', Rule::in(['android'])],
            'app_version' => ['required', 'string', 'max:50', 'regex:/^\d+\.\d+\.\d+$/'],
            'app_build' => ['required', 'integer', 'min:1'],
        ];
    }

    public function clientPlatform(): string
    {
        return $this->string('client_platform')->toString();
    }

    public function appVersion(): string
    {
        return $this->string('app_version')->toString();
    }

    public function appBuild(): int
    {
        return $this->integer('app_build');
    }
}
