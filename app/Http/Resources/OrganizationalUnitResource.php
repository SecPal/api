<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for OrganizationalUnit model.
 *
 * Transforms OrganizationalUnit model data for API responses.
 * Includes hierarchical relationships when loaded.
 *
 * @mixin \App\Models\OrganizationalUnit
 */
class OrganizationalUnitResource extends JsonResource
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
            'type' => $this->type,
            'name' => $this->name,
            'custom_type_name' => $this->custom_type_name,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'parent' => new OrganizationalUnitResource($this->whenLoaded('parent')),
            'children' => OrganizationalUnitResource::collection($this->whenLoaded('children')),
            'ancestors' => OrganizationalUnitResource::collection($this->whenLoaded('ancestors')),
            'descendants' => OrganizationalUnitResource::collection($this->whenLoaded('descendants')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
