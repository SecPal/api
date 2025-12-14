<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update Site Request validation.
 *
 * All fields are optional for partial updates (PATCH).
 * Uses same validation rules as StoreSiteRequest but nullable.
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#314 Site CRUD API endpoints
 */
class UpdateSiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by SitePolicy
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
        /** @var \App\Models\Site $site */
        $site = $this->route('site');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'site_number' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('sites', 'site_number')
                    ->where('tenant_id', $tenantId)
                    ->ignore($site->id),
            ],
            'customer_id' => [
                'sometimes',
                'uuid',
                Rule::exists('customers', 'id')
                    ->where('tenant_id', $tenantId),
            ],
            'organizational_unit_id' => [
                'sometimes',
                'uuid',
                Rule::exists('organizational_units', 'id')
                    ->where('tenant_id', $tenantId),
            ],
            'type' => ['sometimes', 'in:permanent,temporary'],
            'address' => ['sometimes', 'array'],
            'address.street' => ['required_with:address', 'string', 'max:255'],
            'address.city' => ['required_with:address', 'string', 'max:255'],
            'address.postal_code' => ['required_with:address', 'string', 'max:20'],
            'address.country' => ['required_with:address', 'string', 'size:2'], // ISO 3166-1 alpha-2
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'contact' => ['sometimes', 'nullable', 'array'],
            'contact.name' => ['nullable', 'string', 'max:255'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:50'],
            'access_instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after:valid_from'],
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
            'customer_id' => 'customer',
            'organizational_unit_id' => 'organizational unit',
            'address.street' => 'street',
            'address.city' => 'city',
            'address.postal_code' => 'postal code',
            'address.country' => 'country',
            'address.latitude' => 'latitude',
            'address.longitude' => 'longitude',
            'contact.name' => 'contact name',
            'contact.email' => 'contact email',
            'contact.phone' => 'contact phone',
            'valid_from' => 'valid from date',
            'valid_until' => 'valid until date',
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
            'type.in' => 'The type must be either permanent or temporary.',
            'valid_until.after' => 'The valid until date must be after the valid from date.',
        ];
    }
}
