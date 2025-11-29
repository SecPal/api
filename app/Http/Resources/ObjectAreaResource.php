<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for ObjectArea model.
 *
 * Transforms ObjectArea model data for API responses.
 *
 * @mixin \App\Models\ObjectArea
 */
class ObjectAreaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'requires_separate_guard_book' => $this->requires_separate_guard_book,
            'metadata' => $this->metadata,
            'object' => new SecPalObjectResource($this->whenLoaded('object')),
            'guard_book' => new GuardBookResource($this->whenLoaded('guardBook')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
