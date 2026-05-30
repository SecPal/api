<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for CustomerAssignment model.
 *
 * Transforms CustomerAssignment instances into JSON responses for the API.
 * Includes related user and customer data when loaded.
 *
 * @property \App\Models\CustomerAssignment $resource
 *
 * @see \App\Models\CustomerAssignment
 * @see SecPal/api#315 Assignment API endpoints
 */
class CustomerAssignmentResource extends JsonResource
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
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'created_at' => \App\Support\ApiTimestamp::format($this->resource->created_at),
            'updated_at' => \App\Support\ApiTimestamp::format($this->resource->updated_at),
        ];
    }
}
