<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Support\ApiTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WorkInstruction */
final class WorkInstructionResource extends JsonResource
{
    /** @var list<string> */
    public const FIELDS = [
        'id',
        'instruction_number',
        'title',
        'body',
        'locale',
        'status',
        'published_at',
        'published_by_user_id',
        'archived_at',
        'archived_by_user_id',
        'created_at',
        'updated_at',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'instruction_number' => $this->instruction_number,
            'title' => $this->title,
            'body' => $this->body,
            'locale' => $this->locale->value,
            'status' => $this->status->value,
            'published_at' => ApiTimestamp::nullable($this->published_at),
            'published_by_user_id' => $this->published_by_user_id,
            'archived_at' => ApiTimestamp::nullable($this->archived_at),
            'archived_by_user_id' => $this->archived_by_user_id,
            'created_at' => ApiTimestamp::format($this->created_at),
            'updated_at' => ApiTimestamp::format($this->updated_at),
        ];
    }
}
