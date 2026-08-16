<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for Site model.
 *
 * Transforms Site instances into JSON responses for the API.
 * Minimal implementation for Assignment API support.
 *
 * @property \App\Models\Site $resource
 *
 * @see \App\Models\Site
 * @see SecPal/.github#210 Customer & Site Management Epic
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
        $canUpdate = $this->resolveCanUpdate($request);

        return [
            'id' => $this->resource->id,
            'customer_id' => $this->resource->customer_id,
            'legal_entity_id' => $this->resource->legal_entity_id,
            'establishment_id' => $this->resource->establishment_id,
            'site_number' => $this->resource->site_number,
            'name' => $this->resource->name,
            'type' => $this->resource->type,
            'address' => $this->resource->address,
            'full_address' => $this->resource->full_address,
            'contact' => $this->resource->contact,
            'access_instructions' => $canUpdate ? $this->resource->access_instructions : null,
            'notes' => $canUpdate ? $this->resource->notes : null,
            'metadata' => $this->resource->metadata,
            'is_active' => $this->resource->is_active,
            'is_expired' => $this->resource->is_expired,
            'valid_from' => $this->resource->valid_from?->toDateString(),
            'valid_until' => $this->resource->valid_until?->toDateString(),
            'customer' => $this->whenLoaded('customer', fn () => new CustomerResource($this->resource->customer)),
            'created_at' => \App\Support\ApiTimestamp::format($this->resource->created_at),
            'updated_at' => \App\Support\ApiTimestamp::format($this->resource->updated_at),
            'deleted_at' => \App\Support\ApiTimestamp::nullable($this->resource->deleted_at),
        ];
    }

    private function resolveCanUpdate(Request $request): bool
    {
        $precomputed = $this->resource->getAttribute('_resource_can_update');

        if (is_bool($precomputed)) {
            return $precomputed;
        }

        /** @var \App\Models\User|null $user */
        $user = $request->user();

        return $user?->can('update', $this->resource) ?? false;
    }
}
