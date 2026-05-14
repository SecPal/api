<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api\V1\Address;

use App\Support\AddressDataConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IndexAddressLocalityRequest extends FormRequest
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
            'postal_code' => ['nullable', 'string', 'regex:/^\d{1,5}$/'],
            'locality' => ['nullable', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.$maxLimit],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['postal_code', 'locality'])) {
                return;
            }

            $postal = $this->string('postal_code')->toString();
            $locality = $this->string('locality')->toString();

            $hasPostal = strlen($postal) >= 1;
            $hasLocality = mb_strlen(trim($locality)) >= 2;

            if (! $hasPostal && ! $hasLocality) {
                $validator->errors()->add(
                    'locality',
                    __('Provide postal_code and/or locality (2+ characters).'),
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
