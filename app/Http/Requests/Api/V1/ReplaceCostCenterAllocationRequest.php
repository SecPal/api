<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Validator;

final class ReplaceCostCenterAllocationRequest extends CostCenterAllocationRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return array_merge([
            'serviceBooking' => ['required', 'uuid'],
            'allocations' => ['present', 'array'],
            'allocations.*' => ['required', 'array:internal_cost_center_id,share_bps'],
            'allocations.*.internal_cost_center_id' => ['required', 'uuid', 'distinct:strict'],
            'allocations.*.share_bps' => ['required', 'integer', 'min:1', 'max:10000'],
        ], $this->closedBodyRules([], ['allocations']));
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->mutationValidationData(['serviceBooking' => $this->route('serviceBooking')]);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->rejectMutationQueryParameters($validator);

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $allocations = $this->input('allocations');
            if (! is_array($allocations) || $allocations === []) {
                return;
            }

            $total = 0;
            foreach ($allocations as $allocation) {
                if (! is_array($allocation) || ! is_int($allocation['share_bps'] ?? null)) {
                    return;
                }

                $total += $allocation['share_bps'];
            }

            if ($total !== 10000) {
                $validator->errors()->add('allocations', 'A non-empty allocation split must total exactly 10000 basis points.');
            }
        }];
    }
}
