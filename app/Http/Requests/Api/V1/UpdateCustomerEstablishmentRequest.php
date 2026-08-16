<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\CustomerEstablishment;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateCustomerEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customerEstablishment = $this->route('customer_establishment');

        return $customerEstablishment instanceof CustomerEstablishment
            && ($this->user()?->can('update', $customerEstablishment) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'customer_id' => ['prohibited'],
            'establishment_id' => ['prohibited'],
            'organizational_unit_id' => ['prohibited'],
        ];
    }
}
