<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'site' => new SiteResource($this->whenLoaded('site')),
        ];
    }
}
