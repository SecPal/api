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
 * When `accessible_unit_ids` is set in the request attributes,
 * parent data is filtered to only include accessible units
 * (Need-to-Know principle).
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
            'parent' => $this->transformParent($request),
            'children' => OrganizationalUnitResource::collection($this->whenLoaded('children')),
            'ancestors' => OrganizationalUnitResource::collection($this->whenLoaded('ancestors')),
            'descendants' => OrganizationalUnitResource::collection($this->whenLoaded('descendants')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    /**
     * Transform parent relationship with Need-to-Know filtering.
     *
     * If `accessible_unit_ids` is set in request attributes,
     * only return parent if it's in the accessible list.
     */
    private function transformParent(Request $request): ?OrganizationalUnitResource
    {
        if (! $this->relationLoaded('parent') || $this->parent === null) {
            return null;
        }

        // Check if Need-to-Know filtering is active
        /** @var array<string>|null $accessibleIds */
        $accessibleIds = $request->attributes->get('accessible_unit_ids');

        if ($accessibleIds !== null && ! in_array($this->parent->id, $accessibleIds, true)) {
            // Parent exists but is not accessible - return null
            return null;
        }

        return new OrganizationalUnitResource($this->parent);
    }
}
