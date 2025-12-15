<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
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
        return [
            'id' => $this->resource->id,
            'site_number' => $this->resource->site_number,
            'name' => $this->resource->name,
            'is_active' => $this->resource->is_active,
            'created_at' => $this->resource->created_at->toISOString(),
            'updated_at' => $this->resource->updated_at->toISOString(),
        ];
    }
}
