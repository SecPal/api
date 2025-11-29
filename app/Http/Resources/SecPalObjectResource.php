<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for SecPalObject model.
 *
 * Transforms SecPalObject model data for API responses.
 *
 * @mixin \App\Models\SecPalObject
 */
class SecPalObjectResource extends JsonResource
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
            'object_number' => $this->object_number,
            'name' => $this->name,
            'address' => $this->address,
            'gps_coordinates' => $this->gps_coordinates,
            'metadata' => $this->metadata,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'areas' => ObjectAreaResource::collection($this->whenLoaded('areas')),
            'guard_books' => GuardBookResource::collection($this->whenLoaded('guardBooks')),
            'has_area_segmentation' => $this->hasAreaSegmentation(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
