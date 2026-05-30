<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for SiteAssignment model.
 *
 * Transforms SiteAssignment model data for API responses.
 * Includes user and site relationships when loaded.
 *
 * @see SecPal/api#316 Site Assignment CRUD endpoints
 *
 * @mixin \App\Models\SiteAssignment
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
            'id' => $this->id,
            'site_id' => $this->site_id,
            'user_id' => $this->user_id,
            'role' => $this->role,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'notes' => $this->notes,
            'is_active' => $this->is_active,

            // Relationships
            'user' => new UserResource($this->whenLoaded('user')),
            // site relationship omitted to prevent circular dependency with SiteResource

            // Timestamps
            'created_at' => \App\Support\ApiTimestamp::format($this->created_at),
            'updated_at' => \App\Support\ApiTimestamp::format($this->updated_at),
        ];
    }
}
