<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\BillingUnit;
use App\Http\Middleware\InjectTenantId;
use App\Models\ServiceBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class ServiceBookingRequest extends FormRequest
{
    public const AUTHORITATIVE_CONTRACTS_SHA = 'c530093a599eb468be89d280f965dc37dfb96579';

    /** @var list<string> */
    public const CREATE_FIELDS = ServiceBooking::CREATE_FIELDS;

    /** @var list<string> */
    public const UPDATE_FIELDS = ServiceBooking::MUTABLE_BUSINESS_FIELDS;

    public const QUANTITY_PATTERN = '/\A(?!0(?:\.0{1,4})?\z)(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?\z/D';

    public const UNIT_PRICE_PATTERN = '/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?\z/D';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param  list<string>  $required
     * @return array<string, array<int, mixed>>
     */
    protected function businessRules(array $required): array
    {
        $presence = static fn (string $field): string => in_array($field, $required, true)
            ? 'required'
            : 'sometimes';

        return [
            'contract_id' => [$presence('contract_id'), 'uuid'],
            'service_date' => [$presence('service_date'), 'date_format:Y-m-d'],
            'quantity' => [$presence('quantity'), 'string', 'regex:'.self::QUANTITY_PATTERN],
            'billing_unit' => [$presence('billing_unit'), Rule::enum(BillingUnit::class)],
            'unit_price' => [$presence('unit_price'), 'string', 'regex:'.self::UNIT_PRICE_PATTERN],
        ];
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     * @param  list<string>  $allowedBodyKeys
     * @return array<string, array<int, mixed>>
     */
    protected function closedBodyRules(array $rules, array $allowedBodyKeys): array
    {
        $originalBodyKeys = $this->attributes->get(InjectTenantId::ORIGINAL_BODY_KEYS_ATTRIBUTE, []);

        if (! is_array($originalBodyKeys)) {
            return $rules;
        }

        foreach ($originalBodyKeys as $key) {
            if (is_string($key) && ! in_array($key, $allowedBodyKeys, true)) {
                $rules[$key] = ['prohibited'];
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     * @param  list<string>  $allowedQueryKeys
     * @return array<string, array<int, mixed>>
     */
    protected function closedQueryRules(array $rules, array $allowedQueryKeys): array
    {
        $originalQueryKeys = $this->attributes->get(
            InjectTenantId::ORIGINAL_QUERY_KEYS_ATTRIBUTE,
            array_keys($this->query->all()),
        );

        if (! is_array($originalQueryKeys)) {
            return $rules;
        }

        foreach ($originalQueryKeys as $key) {
            if (is_string($key) && ! in_array($key, $allowedQueryKeys, true)) {
                $rules[$key] = ['prohibited'];
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $routeParameters
     * @return array<string, mixed>
     */
    protected function routeValidationData(array $routeParameters): array
    {
        /** @var array<string, mixed> $data */
        $data = parent::validationData();

        return array_merge($data, $routeParameters);
    }

    /**
     * @param  array<string, mixed>  $routeParameters
     * @return array<string, mixed>
     */
    protected function mutationValidationData(array $routeParameters = []): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->getInputSource()->all();

        return array_merge($data, $routeParameters);
    }

    protected function rejectMutationQueryParameters(Validator $validator): void
    {
        $originalQueryKeys = $this->attributes->get(
            InjectTenantId::ORIGINAL_QUERY_KEYS_ATTRIBUTE,
            array_keys($this->query->all()),
        );

        if (! is_array($originalQueryKeys)) {
            return;
        }

        foreach ($originalQueryKeys as $key) {
            if (is_string($key)) {
                $validator->errors()->add($key, 'Query parameters are not accepted for Service Booking mutations.');
            }
        }
    }
}
