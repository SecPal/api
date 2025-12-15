<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for Site model.
 *
 * Transforms Site model data for API responses.
 * Includes customer, organizational unit, and assignment relationships when loaded.
 *
 * @see SecPal/api#314 Site CRUD API endpoints
 *
 * @mixin \App\Models\Site
 */
class SiteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        // Check if user can update this site (to show access_instructions and notes)
        $canUpdate = $user?->can('update', $this->resource);

        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'organizational_unit_id' => $this->organizational_unit_id,
            'site_number' => $this->site_number,
            'name' => $this->name,
            'type' => $this->type,
            'address' => $this->address,
            'full_address' => $this->full_address,
            'contact' => $this->contact,
            'access_instructions' => $this->when((bool) $canUpdate, $this->access_instructions),
            'notes' => $this->when((bool) $canUpdate, $this->notes),
            'metadata' => $this->metadata,
            'is_active' => $this->is_active,
            'is_expired' => $this->is_expired,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),

            // Relationships
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'organizational_unit' => new OrganizationalUnitResource($this->whenLoaded('organizationalUnit')),
            'assignments' => SiteAssignmentResource::collection($this->whenLoaded('assignments')),
            'assigned_users_count' => $this->whenCounted('assignedUsers'),
            'cost_centers_count' => $this->whenCounted('costCenters'),

            // Timestamps
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
