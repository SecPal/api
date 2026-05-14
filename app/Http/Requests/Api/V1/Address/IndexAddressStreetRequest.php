<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api\V1\Address;

use App\Support\AddressDataConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IndexAddressStreetRequest extends FormRequest
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
        $maxLimit = AddressDataConfig::int('address_data.max_limit', 50);

        return [
            'name' => ['nullable', 'string', 'min:2', 'max:100'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{1,5}$/'],
            'locality' => ['nullable', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.$maxLimit],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['name', 'postal_code', 'locality'])) {
                return;
            }

            $name = $this->string('name')->toString();
            $postal = $this->string('postal_code')->toString();
            $locality = $this->string('locality')->toString();

            $hasName = mb_strlen(trim($name)) >= 2;
            $hasPostal = strlen($postal) >= 1;
            $hasLocality = mb_strlen(trim($locality)) >= 2;

            if (! $hasName && ! $hasPostal && ! $hasLocality) {
                $validator->errors()->add(
                    'name',
                    __('Provide at least one filter: name (2+ characters), locality (2+ characters), or postal_code.'),
                );
            }
        });
    }

    public function limitResolved(): int
    {
        $default = AddressDataConfig::int('address_data.default_limit', 20);
        $limit = $this->integer('limit', $default);
        $cap = AddressDataConfig::int('address_data.max_limit', 50);

        return max(1, min($limit, $cap));
    }
}
