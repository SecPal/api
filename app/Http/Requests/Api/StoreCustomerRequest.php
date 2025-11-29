<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api;

use App\Models\Customer;
use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for storing a new customer.
 */
class StoreCustomerRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'customer_number' => ['required', 'string', 'max:100', 'unique:customers,customer_number'],
            'type' => ['required', 'string', Rule::in(['corporate', 'regional', 'local', 'custom'])],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
            'parent_id' => ['nullable', 'uuid', Rule::exists(Customer::class, 'id')],
            'managed_by_organizational_unit_id' => ['nullable', 'uuid', Rule::exists(OrganizationalUnit::class, 'id')],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_number.unique' => 'This customer number is already in use.',
            'parent_id.exists' => 'The selected parent customer does not exist.',
            'managed_by_organizational_unit_id.exists' => 'The selected organizational unit does not exist.',
        ];
    }
}
