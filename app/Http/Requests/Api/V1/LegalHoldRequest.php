<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Middleware\InjectTenantId;
use Illuminate\Foundation\Http\FormRequest;

abstract class LegalHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param  array<string, array<int, string>>  $rules
     * @param  list<string>  $allowedBodyKeys
     * @return array<string, array<int, string>>
     */
    protected function closedBodyRules(array $rules, array $allowedBodyKeys): array
    {
        $originalBodyKeys = $this->attributes->get(
            InjectTenantId::ORIGINAL_BODY_KEYS_ATTRIBUTE,
            [],
        );

        if (! is_array($originalBodyKeys)) {
            return $rules;
        }

        foreach ($originalBodyKeys as $key) {
            if (is_string($key) && ! in_array($key, $allowedBodyKeys, true)) {
                $rules[$key] = ['prohibited'];
            }
        }

        return $rules;
    }

    protected function trimStringInput(string $key): void
    {
        $value = $this->input($key);

        if (is_string($value)) {
            $this->merge([$key => trim($value)]);
        }
    }

    /**
     * @param  array<string, mixed>  $routeParameters
     * @return array<string, mixed>
     */
    protected function routeValidationData(array $routeParameters): array
    {
        /** @var array<string, mixed> $data */
        $data = parent::validationData();

        return array_merge($data, $routeParameters);
    }
}
