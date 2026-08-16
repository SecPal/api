<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api\V1;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update Customer Request validation.
 *
 * All fields are optional for partial updates.
 * Validates legal-entity-wide master data when provided.
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
        /** @var int $tenantId */
        $tenantId = $this->input('tenant_id');
        /** @var Customer|null $customer */
        $customer = $this->route('customer');
        $legalEntityExists = Rule::exists('legal_entities', 'id')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNull('deleted_at');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'vat_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'legal_entity_id' => [
                'sometimes',
                'uuid',
                $legalEntityExists,
            ],
            'billing_address' => ['sometimes', 'array'],
            'billing_address.street' => ['required_with:billing_address', 'string', 'max:255'],
            'billing_address.city' => ['required_with:billing_address', 'string', 'max:255'],
            'billing_address.postal_code' => ['required_with:billing_address', 'string', 'max:20'],
            'billing_address.country' => ['required_with:billing_address', 'string', 'size:2'],
            'is_active' => ['sometimes', 'boolean'],
            'organizational_unit_id' => ['prohibited'],
            'contact' => ['prohibited'],
            'notes' => ['prohibited'],
            'metadata' => ['prohibited'],
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
            'legal_entity_id' => 'legal entity',
        ];
    }
}
