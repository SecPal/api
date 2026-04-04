<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for SiteAssignment model.
 *
 * Transforms SiteAssignment instances into JSON responses for the API.
 * Includes related user and site data when loaded.
 *
 * @property \App\Models\SiteAssignment $resource
 *
 * @see \App\Models\SiteAssignment
 * @see SecPal/api#315 Assignment API endpoints
 */
class SiteAssignmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'role' => $this->resource->role,
            'is_active' => $this->resource->is_active,
            'valid_from' => $this->resource->valid_from?->toDateString(),
            'valid_until' => $this->resource->valid_until?->toDateString(),
            'notes' => $this->resource->notes,
            'user' => new UserResource($this->whenLoaded('user')),
            'site' => new SiteResource($this->whenLoaded('site')),
            'created_at' => $this->resource->created_at->toIso8601String(),
            'updated_at' => $this->resource->updated_at->toIso8601String(),
        ];
    }
}
