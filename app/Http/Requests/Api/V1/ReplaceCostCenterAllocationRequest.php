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
            'allocations' => ['present', 'array', 'list'],
            'allocations.*' => ['required', 'array:internal_cost_center_id,share_bps'],
            'allocations.*.internal_cost_center_id' => ['required', 'uuid', 'distinct:strict'],
            'allocations.*.share_bps' => ['required', 'integer:strict', 'min:1', 'max:10000'],
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
            $this->rejectIntegerNormalizedBodyKeys($validator);

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $decoded = json_decode($this->getContent());
            if (is_object($decoded) && property_exists($decoded, 'allocations')) {
                if (! is_array($decoded->allocations)) {
                    $validator->errors()->add('allocations', 'The allocations property must be a JSON array.');

                    return;
                }

                foreach ($decoded->allocations as $index => $allocation) {
                    if (! is_object($allocation)) {
                        $validator->errors()->add("allocations.{$index}", 'Each allocation must be a JSON object.');
                    }
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $allocations = $this->input('allocations');
            if (! is_array($allocations) || $allocations === []) {
                return;
            }

            $total = 0;
            $targetIds = [];
            foreach ($allocations as $index => $allocation) {
                if (! is_array($allocation) || ! is_int($allocation['share_bps'] ?? null)) {
                    return;
                }

                $total += $allocation['share_bps'];
                $targetId = $allocation['internal_cost_center_id'] ?? null;
                if (! is_string($targetId)) {
                    return;
                }

                $canonicalTargetId = strtolower($targetId);
                if (isset($targetIds[$canonicalTargetId])) {
                    $validator->errors()->add(
                        "allocations.{$index}.internal_cost_center_id",
                        'Each allocation target must be unique.',
                    );
                }
                $targetIds[$canonicalTargetId] = true;
            }

            if ($total !== 10000) {
                $validator->errors()->add('allocations', 'A non-empty allocation split must total exactly 10000 basis points.');
            }
        }];
    }
}
