<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Middleware\InjectTenantId;
use App\Models\Contract;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class UpdateContractRequest extends ContractRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return array_merge(
            ['contract' => ['required', 'uuid']],
            $this->closedBodyRules($this->businessRules([]), self::BUSINESS_FIELDS),
        );
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->routeValidationData(['contract' => $this->route('contract')]);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $originalBodyKeys = $this->attributes->get(
                    InjectTenantId::ORIGINAL_BODY_KEYS_ATTRIBUTE,
                    [],
                );

                if (is_array($originalBodyKeys) && $originalBodyKeys === []) {
                    $validator->errors()->add('contract', 'At least one Contract property is required.');
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $contractId = $this->route('contract');
                $tenantId = $this->input('tenant_id');
                if (! is_string($contractId) || ! Str::isUuid($contractId) || ! is_int($tenantId)) {
                    return;
                }

                $contract = Contract::query()
                    ->forTenant($tenantId)
                    ->whereKey($contractId)
                    ->first();

                if ($contract === null) {
                    return;
                }

                $startsOn = $this->has('starts_on')
                    ? $this->input('starts_on')
                    : $contract->starts_on->format('Y-m-d');
                $endsOn = $this->exists('ends_on')
                    ? $this->input('ends_on')
                    : $contract->ends_on?->format('Y-m-d');

                if (is_string($startsOn) && is_string($endsOn) && $endsOn < $startsOn) {
                    $validator->errors()->add('ends_on', 'The resulting end date must be on or after the start date.');
                }
            },
        ];
    }
}
