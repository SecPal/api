<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\BillingUnit;
use App\Enums\ContractType;
use App\Http\Middleware\InjectTenantId;
use App\Models\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ContractRequest extends FormRequest
{
    public const AUTHORITATIVE_CONTRACTS_SHA = '97c9590b1286b78a1a0dc69af7860a14be142156';

    /** @var list<string> */
    public const BUSINESS_FIELDS = Contract::MUTABLE_BUSINESS_FIELDS;

    public const UNIT_PRICE_PATTERN = '/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?\z/D';

    public const CURRENCY_CODE_PATTERN = '/\A[A-Z]{3}\z/D';

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
            'customer_id' => [$presence('customer_id'), 'uuid'],
            'type' => [$presence('type'), Rule::enum(ContractType::class)],
            'starts_on' => [$presence('starts_on'), 'date_format:Y-m-d'],
            'ends_on' => [$presence('ends_on'), 'nullable', 'date_format:Y-m-d'],
            'billing_unit' => [$presence('billing_unit'), Rule::enum(BillingUnit::class)],
            'unit_price' => [$presence('unit_price'), 'string', 'regex:'.self::UNIT_PRICE_PATTERN],
            'currency_code' => [$presence('currency_code'), 'string', 'regex:'.self::CURRENCY_CODE_PATTERN],
        ];
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     * @param  list<string>  $allowedBodyKeys
     * @return array<string, array<int, mixed>>
     */
    protected function closedBodyRules(array $rules, array $allowedBodyKeys): array
    {
        $originalBodyKeys = $this->attributes->get(
            InjectTenantId::ORIGINAL_BODY_KEYS_ATTRIBUTE,
            [],
        );

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
            if (! is_string($key)) {
                continue;
            }

            if (! in_array($key, $allowedQueryKeys, true)) {
                $rules[$key] = $key === 'tenant_id'
                    ? [static function (string $attribute, mixed $value, \Closure $fail): void {
                        $fail('The tenant_id field is not accepted.');
                    }]
                    : ['prohibited'];
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
}
