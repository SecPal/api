<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests\Api\V1\Address;

use App\Http\Requests\Concerns\InteractsWithAddressLimit;
use App\Support\AddressDataConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IndexAddressStreetRequest extends FormRequest
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

            $rawName = $this->input('name');
            $rawPostal = $this->input('postal_code');
            $rawLocality = $this->input('locality');

            $name = is_scalar($rawName) ? (string) $rawName : '';
            $postal = is_scalar($rawPostal) ? (string) $rawPostal : '';
            $locality = is_scalar($rawLocality) ? (string) $rawLocality : '';

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
}
