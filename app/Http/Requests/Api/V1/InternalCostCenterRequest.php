<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Middleware\InjectTenantId;
use App\Models\InternalCostCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class InternalCostCenterRequest extends FormRequest
{
    public const AUTHORITATIVE_CONTRACTS_SHA = 'a1bd32f3ccd634fd25d43700539cabea508ff06d';

    /** @var list<string> */
    public const CREATE_FIELDS = InternalCostCenter::CREATE_FIELDS;

    /** @var list<string> */
    public const UPDATE_FIELDS = InternalCostCenter::MUTABLE_BUSINESS_FIELDS;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    protected function businessRules(bool $create): array
    {
        $presence = $create ? 'required' : 'sometimes';

        return [
            'code' => [$presence, 'string', 'max:64', 'not_regex:/\A\s*\z/u'],
            'name' => [$presence, 'string', 'max:255', 'not_regex:/\A\s*\z/u'],
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
                $rules[$key] = ['filled', 'prohibited'];
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
            if (is_int($key) || is_string($key)) {
                $validator->errors()->add((string) $key, 'Query parameters are not accepted for Internal Cost Center mutations.');
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
