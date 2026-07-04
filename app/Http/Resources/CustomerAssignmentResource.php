<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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
            'user' => $this->whenLoaded('user', fn (): ?UserResource => $this->user === null ? null : new UserResource($this->user)),
            // customer relationship omitted to prevent circular dependency with CustomerResource

            // Timestamps
            'created_at' => \App\Support\ApiTimestamp::format($this->created_at),
            'updated_at' => \App\Support\ApiTimestamp::format($this->updated_at),
        ];
    }
}
