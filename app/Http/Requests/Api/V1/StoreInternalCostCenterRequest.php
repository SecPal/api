<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Validator;

final class StoreInternalCostCenterRequest extends InternalCostCenterRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->closedBodyRules($this->businessRules(true), self::CREATE_FIELDS);
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->mutationValidationData();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectMutationQueryParameters($validator)];
    }
}
