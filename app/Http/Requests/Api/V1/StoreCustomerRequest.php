<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api\V1;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Store Customer Request validation.
 *
 * Validates customer creation with required fields:
 * - name (company/organization name)
 * - billing_address (structured JSON)
 * - contact (optional primary contact)
 *
 * @see SecPal/api#313 Customer CRUD API endpoints
 */
class StoreCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Customer::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var int $tenantId */
        $tenantId = $this->input('tenant_id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'customer_number' => [
                'nullable',
                'string',
                'max:50',
                \Illuminate\Validation\Rule::unique('customers', 'customer_number')
                    ->where('tenant_id', $tenantId),
            ],
            'billing_address' => ['required', 'array'],
            'billing_address.street' => ['required', 'string', 'max:255'],
            'billing_address.city' => ['required', 'string', 'max:255'],
            'billing_address.postal_code' => ['required', 'string', 'max:20'],
            'billing_address.country' => ['required', 'string', 'size:2'], // ISO 3166-1 alpha-2
            'contact' => ['nullable', 'array'],
            'contact.name' => ['nullable', 'string', 'max:255'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
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
