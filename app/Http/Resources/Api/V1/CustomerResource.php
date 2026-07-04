<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for Customer model.
 *
 * Transforms Customer instances into JSON responses for the API.
 * Minimal implementation for Assignment API support.
 *
 * @property \App\Models\Customer $resource
 *
 * @see \App\Models\Customer
 * @see SecPal/.github#210 Customer & Site Management Epic
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
        $canUpdate = $this->resolveCanUpdate($request);

        return [
            'id' => $this->resource->id,
            'customer_number' => $this->resource->customer_number,
            'name' => $this->resource->name,
            'billing_address' => $this->resource->billing_address,
            'contact' => $this->resource->contact,
            'notes' => $canUpdate ? $this->resource->notes : null,
            'metadata' => $this->resource->metadata,
            'is_active' => $this->resource->is_active,
            'sites_count' => $this->whenCounted('sites'),
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
