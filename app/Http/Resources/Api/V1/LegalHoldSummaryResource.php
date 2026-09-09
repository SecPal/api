<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Support\ApiTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LegalHold */
final class LegalHoldSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'case_reference' => $this->case_reference,
            'status' => $this->status->value,
            'created_at' => ApiTimestamp::format($this->created_at),
            'released_at' => ApiTimestamp::nullable($this->released_at),
        ];
    }
}
