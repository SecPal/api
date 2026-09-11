<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Middleware\InjectTenantId;
use Illuminate\Validation\Validator;

final class UpdateInternalCostCenterRequest extends InternalCostCenterRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = $this->businessRules(false);
        unset($rules['code']);

        return array_merge(
            ['internalCostCenter' => ['required', 'uuid']],
            $this->closedBodyRules($rules, self::UPDATE_FIELDS),
        );
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->mutationValidationData(['internalCostCenter' => $this->route('internalCostCenter')]);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->rejectMutationQueryParameters($validator);
            $this->rejectIntegerNormalizedBodyKeys($validator);
            $originalBodyKeys = $this->attributes->get(InjectTenantId::ORIGINAL_BODY_KEYS_ATTRIBUTE, []);

            if (is_array($originalBodyKeys) && $originalBodyKeys === []) {
                $validator->errors()->add('internalCostCenter', 'The name property is required.');
            }
        }];
    }
}
