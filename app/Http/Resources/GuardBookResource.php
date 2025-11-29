<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for GuardBook model.
 *
 * Transforms GuardBook model data for API responses.
 *
 * @mixin \App\Models\GuardBook
 */
class GuardBookResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'is_area_specific' => $this->isAreaSpecific(),
            'object' => new SecPalObjectResource($this->whenLoaded('object')),
            'object_area' => new ObjectAreaResource($this->whenLoaded('objectArea')),
            'reports_count' => $this->when(isset($this->reports_count), $this->reports_count),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
