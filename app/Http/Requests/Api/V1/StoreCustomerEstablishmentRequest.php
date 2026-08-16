<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\CustomerEstablishment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCustomerEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CustomerEstablishment::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->integer('tenant_id');

        return [
            'customer_id' => [
                'required',
                'uuid',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'establishment_id' => [
                'required',
                'uuid',
                Rule::exists('establishments', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'organizational_unit_id' => ['prohibited'],
        ];
    }
}
