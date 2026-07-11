<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests\Api\V1;

use App\Models\Site;
use App\Rules\AssignableOrganizationalUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Store Site Request validation.
 *
 * Validates site creation with required fields:
 * - name (site/object name)
 * - customer_id (foreign key to customers)
 * - organizational_unit_id (internal unit responsible)
 * - type (permanent or temporary)
 * - address (structured JSON with GPS coordinates)
 * - contact (optional on-site contact)
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#314 Site CRUD API endpoints
 */
class StoreSiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Site::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var int $tenantId */
        $tenantId = $this->get('tenant_id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'site_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('sites', 'site_number')
                    ->where('tenant_id', $tenantId),
            ],
            'customer_id' => [
                'required',
                'uuid',
                Rule::exists('customers', 'id')
                    ->where('tenant_id', $tenantId),
            ],
            'organizational_unit_id' => [
                'required',
                'uuid',
                Rule::exists('organizational_units', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
                new AssignableOrganizationalUnit($tenantId),
            ],
            'type' => ['required', 'in:permanent,temporary'],
            'address' => ['required', 'array'],
            'address.street' => ['required', 'string', 'max:255'],
            'address.city' => ['required', 'string', 'max:255'],
            'address.postal_code' => ['required', 'string', 'max:20'],
            'address.country' => ['required', 'string', 'size:2'], // ISO 3166-1 alpha-2
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'contact' => ['nullable', 'array'],
            'contact.name' => ['nullable', 'string', 'max:255'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:50'],
            'access_instructions' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
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
