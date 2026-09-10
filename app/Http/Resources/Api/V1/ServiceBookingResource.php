<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Support\ApiTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ServiceBooking */
final class ServiceBookingResource extends JsonResource
{
    /** @var list<string> */
    public const FIELDS = [
        'id',
        'contract_id',
        'service_date',
        'quantity',
        'billing_unit',
        'unit_price',
        'currency_code',
        'total',
        'invoice_state',
        'invoiced_at',
        'status',
        'retired_at',
        'created_at',
        'updated_at',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'service_date' => $this->service_date->format('Y-m-d'),
            'quantity' => (string) $this->quantity,
            'billing_unit' => $this->billing_unit->value,
            'unit_price' => (string) $this->unit_price,
            'currency_code' => $this->currency_code,
            'total' => (string) $this->total,
            'invoice_state' => $this->invoice_state->value,
            'invoiced_at' => ApiTimestamp::nullable($this->invoiced_at),
            'status' => $this->status->value,
            'retired_at' => ApiTimestamp::nullable($this->retired_at),
            'created_at' => ApiTimestamp::format($this->created_at),
            'updated_at' => ApiTimestamp::format($this->updated_at),
        ];
    }
}
