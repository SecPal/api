<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Validator;

final class StoreContractRequest extends ContractRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->closedBodyRules($this->businessRules([
            'customer_id',
            'type',
            'starts_on',
            'billing_unit',
            'unit_price',
            'currency_code',
        ]), self::BUSINESS_FIELDS);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $startsOn = $this->input('starts_on');
                $endsOn = $this->input('ends_on');

                if (is_string($startsOn) && is_string($endsOn) && $endsOn < $startsOn) {
                    $validator->errors()->add('ends_on', 'The end date must be on or after the start date.');
                }
            },
        ];
    }
}
