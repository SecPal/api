<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for CostCenter model.
 *
 * Transforms CostCenter model data for API responses.
 *
 * @see SecPal/.github#316 CostCenter API endpoints
 *
 * @mixin \App\Models\CostCenter
 */
class CostCenterResource extends JsonResource
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
            'code' => $this->code,
            'name' => $this->name,
            'activity_type' => $this->activity_type,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => \App\Support\ApiTimestamp::format($this->created_at),
            'updated_at' => \App\Support\ApiTimestamp::format($this->updated_at),
            'site' => new SiteResource($this->whenLoaded('site')),
        ];
    }
}
