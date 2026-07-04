<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests\Api\V1;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update Customer Request validation.
 *
 * All fields are optional for partial updates.
 * Validates nested address and contact fields when provided.
 *
 * @see SecPal/api#313 Customer CRUD API endpoints
 */
class UpdateCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Customer|null $customer */
        $customer = $this->route('customer');

        return $customer !== null && ($this->user()?->can('update', $customer) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'billing_address' => ['sometimes', 'array'],
            'billing_address.street' => ['required_with:billing_address', 'string', 'max:255'],
            'billing_address.city' => ['required_with:billing_address', 'string', 'max:255'],
            'billing_address.postal_code' => ['required_with:billing_address', 'string', 'max:20'],
            'billing_address.country' => ['required_with:billing_address', 'string', 'size:2'],
            'contact' => ['nullable', 'array'],
            'contact.name' => ['nullable', 'string', 'max:255'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get custom attribute names for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'billing_address.street' => 'billing street',
            'billing_address.city' => 'billing city',
            'billing_address.postal_code' => 'billing postal code',
            'billing_address.country' => 'billing country',
            'contact.name' => 'contact name',
            'contact.email' => 'contact email',
            'contact.phone' => 'contact phone',
        ];
    }
}
