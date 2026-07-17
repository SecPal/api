<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Requests\Api\V1;

use App\Models\Site;
use App\Services\DomainAccessService;
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
        /** @var Site|null $site */
        $site = $this->route('site');

        $user = $this->user();
        if ($site === null || $user === null || ! $user->can('update', $site)) {
            return false;
        }

        $reassignsDomain = $this->exists('customer_id')
            || $this->exists('legal_entity_id')
            || $this->exists('establishment_id');

        return ! $reassignsDomain || ! $user->organizationalScopes()->exists();
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
        /** @var Site $site */
        $site = $this->route('site');
        $customerId = $this->exists('customer_id') ? $this->string('customer_id')->toString() : $site->customer_id;
        $legalEntityId = $this->exists('legal_entity_id') ? $this->string('legal_entity_id')->toString() : $site->legal_entity_id;

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
            'legal_entity_id' => [
                'sometimes',
                'uuid',
                Rule::exists('customers', 'legal_entity_id')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $customerId),
            ],
            'establishment_id' => [
                'sometimes',
                'uuid',
                Rule::exists('customer_establishments', 'establishment_id')
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $customerId)
                    ->where('legal_entity_id', $legalEntityId)
                    ->whereNull('deleted_at'),
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
     * Configure the validator instance.
     *
     * Adds custom validation to ensure valid_until is after the existing
     * valid_from value in the database when valid_from is not provided in the request.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $reassignsDomain = $this->exists('customer_id')
                || $this->exists('legal_entity_id')
                || $this->exists('establishment_id');

            if ($reassignsDomain && ! $validator->errors()->hasAny([
                'customer_id',
                'legal_entity_id',
                'establishment_id',
            ])) {
                /** @var Site $site */
                $site = $this->route('site');
                $customerId = $this->string('customer_id', $site->customer_id)->toString();
                $legalEntityId = $this->string('legal_entity_id', $site->legal_entity_id)->toString();
                $establishmentId = $this->string('establishment_id', $site->establishment_id)->toString();

                if (! app(DomainAccessService::class)->siteDomainIsActive(
                    $this->integer('tenant_id'),
                    $customerId,
                    $legalEntityId,
                    $establishmentId,
                )) {
                    $validator->errors()->add(
                        'establishment_id',
                        __('The selected customer, legal entity, and establishment combination is invalid.'),
                    );
                }
            }

            // Only validate if valid_until is provided but valid_from is not
            if ($this->has('valid_until') && ! $this->has('valid_from')) {
                /** @var Site $site */
                $site = $this->route('site');
                $validUntil = $this->date('valid_until');

                // Compare against existing database value
                if ($site->valid_from && $validUntil && $validUntil->lt($site->valid_from)) {
                    $validator->errors()->add(
                        'valid_until',
                        __('The valid until date must be after the existing valid from date.')
                    );
                }
            }
        });
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
            'legal_entity_id' => 'legal entity',
            'establishment_id' => 'establishment',
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
