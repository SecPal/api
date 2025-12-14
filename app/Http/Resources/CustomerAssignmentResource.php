<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for CustomerAssignment model.
 *
 * Transforms CustomerAssignment model data for API responses.
 * Includes user and customer relationships when loaded.
 *
 * @see SecPal/api#315 Customer Assignment CRUD endpoints
 *
 * @mixin \App\Models\CustomerAssignment
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
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'user_id' => $this->user_id,
            'role' => $this->role,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'notes' => $this->notes,
            'is_active' => $this->is_active,

            // Relationships
            'user' => new UserResource($this->whenLoaded('user')),
            // customer relationship omitted to prevent circular dependency with CustomerResource

            // Timestamps
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
