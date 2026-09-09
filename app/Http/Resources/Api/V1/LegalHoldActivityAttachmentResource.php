<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Support\ApiTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LegalHoldActivityAttachment */
final class LegalHoldActivityAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'activity_id' => $this->activity_identity_id,
            'attached_at' => ApiTimestamp::format($this->attached_at),
            'detached_at' => ApiTimestamp::nullable($this->detached_at),
            'detachment_justification' => $this->detachment_justification,
        ];
    }
}
