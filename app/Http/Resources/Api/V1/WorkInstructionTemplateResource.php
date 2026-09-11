<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Support\ApiTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WorkInstructionTemplate */
final class WorkInstructionTemplateResource extends JsonResource
{
    /** @var list<string> */
    public const FIELDS = ['id', 'translations', 'localized', 'created_at', 'updated_at'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'translations' => $this->getAttribute('public_translations'),
            'localized' => $this->getAttribute('localized_content'),
            'created_at' => ApiTimestamp::format($this->created_at),
            'updated_at' => ApiTimestamp::format($this->updated_at),
        ];
    }
}
