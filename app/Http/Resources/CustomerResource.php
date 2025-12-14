<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for Customer model.
 *
 * Transforms Customer model data for API responses.
 * Conditionally includes sensitive fields (notes) based on authorization.
 * Includes relationships when loaded: sites, assignments.
 *
 * @see SecPal/api#313 Customer CRUD API endpoints
 *
 * @mixin \App\Models\Customer
 */
class CustomerResource extends JsonResource
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

        // Check if user can update this customer (to show notes)
        $canUpdate = $user?->can('update', $this->resource);

        return [
            'id' => $this->id,
            'customer_number' => $this->customer_number,
            'name' => $this->name,
            'billing_address' => $this->billing_address,
            'contact' => $this->contact,
            'notes' => $this->when((bool) $canUpdate, $this->notes),
            'metadata' => $this->metadata,
            'is_active' => $this->is_active,

            // Relationships
            'sites_count' => $this->whenCounted('sites'),
            'sites' => SiteResource::collection($this->whenLoaded('sites')),
            'assignments' => CustomerAssignmentResource::collection($this->whenLoaded('assignments')),

            // Timestamps
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
