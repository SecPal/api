<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Middleware\InjectTenantId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class CostCenterAllocationRequest extends FormRequest
{
    public const AUTHORITATIVE_CONTRACTS_SHA = InternalCostCenterRequest::AUTHORITATIVE_CONTRACTS_SHA;

    /** @var list<string> */
    public const ITEM_FIELDS = ['internal_cost_center_id', 'share_bps'];

    public function authorize(): bool
    {
        return true;
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
                $rules[$key] = ['filled', 'prohibited'];
            }
        }

        return $rules;
    }

    /** @param array<string, mixed> $routeParameters
     * @return array<string, mixed>
     */
    protected function routeValidationData(array $routeParameters): array
    {
        /** @var array<string, mixed> $data */
        $data = parent::validationData();

        return array_merge($data, $routeParameters);
    }

    /** @param array<string, mixed> $routeParameters
     * @return array<string, mixed>
     */
    protected function mutationValidationData(array $routeParameters): array
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
            if (is_int($key) || is_string($key)) {
                $validator->errors()->add((string) $key, 'Query parameters are not accepted for allocation replacement.');
            }
        }
    }

    protected function rejectIntegerNormalizedBodyKeys(Validator $validator): void
    {
        $originalBodyKeys = $this->attributes->get(InjectTenantId::ORIGINAL_BODY_KEYS_ATTRIBUTE, []);

        if (! is_array($originalBodyKeys)) {
            return;
        }

        foreach ($originalBodyKeys as $key) {
            if (is_int($key)) {
                $validator->errors()->add((string) $key, 'Numeric JSON property names are not accepted.');
            }
        }
    }
}
