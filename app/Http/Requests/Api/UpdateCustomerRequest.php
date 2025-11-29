<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api;

use App\Models\Customer;
use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for updating a customer.
 */
class UpdateCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by controller via policy
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'customer_number' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('customers', 'customer_number')->ignore($customer->id),
            ],
            'type' => ['sometimes', 'string', Rule::in(['corporate', 'regional', 'local', 'custom'])],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
            'managed_by_organizational_unit_id' => ['nullable', 'uuid', Rule::exists(OrganizationalUnit::class, 'id')],
        ];
    }
}
