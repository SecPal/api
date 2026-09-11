<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ServiceBooking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceBooking */
final class CostCenterAllocationResource extends JsonResource
{
    /** @var list<string> */
    public const ITEM_FIELDS = ['internal_cost_center_id', 'share_bps'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'service_booking_id' => $this->id,
            'allocations' => $this->costCenterAllocations
                ->sortBy('internal_cost_center_id', SORT_STRING)
                ->values()
                ->map(static fn ($allocation): array => [
                    'internal_cost_center_id' => $allocation->internal_cost_center_id,
                    'share_bps' => $allocation->share_bps,
                ])->all(),
        ];
    }
}
