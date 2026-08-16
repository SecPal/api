<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api\V1\Address;

use App\Http\Requests\Concerns\InteractsWithAddressLimit;
use App\Support\AddressDataConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IndexAddressLocalityRequest extends FormRequest
{
    use InteractsWithAddressLimit;

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

            $rawPostal = $this->input('postal_code');
            $rawLocality = $this->input('locality');

            $postal = is_scalar($rawPostal) ? (string) $rawPostal : '';
            $locality = is_scalar($rawLocality) ? (string) $rawLocality : '';

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
}
