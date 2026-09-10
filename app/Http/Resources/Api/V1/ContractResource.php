<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Support\ApiTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Contract */
final class ContractResource extends JsonResource
{
    /** @var list<string> */
    public const FIELDS = [
        'id',
        'customer_id',
        'type',
        'status',
        'starts_on',
        'ends_on',
        'billing_unit',
        'unit_price',
        'currency_code',
        'retired_at',
        'created_at',
        'updated_at',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'starts_on' => $this->starts_on->format('Y-m-d'),
            'ends_on' => $this->ends_on?->format('Y-m-d'),
            'billing_unit' => $this->billing_unit->value,
            'unit_price' => (string) $this->unit_price,
            'currency_code' => $this->currency_code,
            'retired_at' => ApiTimestamp::nullable($this->retired_at),
            'created_at' => ApiTimestamp::format($this->created_at),
            'updated_at' => ApiTimestamp::format($this->updated_at),
        ];
    }
}
