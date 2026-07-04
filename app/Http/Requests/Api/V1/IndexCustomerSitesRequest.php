<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests\Api\V1;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * IndexCustomerSitesRequest validates customer site list filters.
 */
class IndexCustomerSitesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Customer|null $customer */
        $customer = $this->route('customer');

        return $customer !== null
            && ($this->user()?->can('view', $customer) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['nullable', 'boolean'],
            'type' => ['nullable', 'in:permanent,temporary'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
