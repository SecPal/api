<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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
        return [
            'id' => $this->id,
            'legal_entity_id' => $this->legal_entity_id,
            'customer_number' => $this->customer_number,
            'name' => $this->name,
            'vat_id' => $this->vat_id,
            'billing_address' => $this->billing_address,
            'is_active' => $this->is_active,

            // Relationships
            'sites_count' => $this->whenCounted('sites'),
            'sites' => SiteResource::collection($this->whenLoaded('sites')),
            'assignments' => CustomerAssignmentResource::collection($this->whenLoaded('assignments')),

            // Timestamps
            'created_at' => \App\Support\ApiTimestamp::format($this->created_at),
            'updated_at' => \App\Support\ApiTimestamp::format($this->updated_at),
            'deleted_at' => \App\Support\ApiTimestamp::nullable($this->deleted_at),
        ];
    }
}
