<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LeadershipLevelResource transforms LeadershipLevel models into API responses.
 *
 * Represents hierarchical leadership levels within a tenant for access control
 * filtering per ADR-009 (Leadership-Based Access Control).
 *
 * @mixin \App\Models\LeadershipLevel
 *
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/424 Issue #424: Leadership Levels Backend API
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251221-inheritance-blocking-and-leadership-access-control.md ADR-009
 */
class LeadershipLevelResource extends JsonResource
{
    /**
     * Disable wrapping for single resources.
     *
     * Collections will still be wrapped in 'data' key.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * Exposes all relevant leadership level attributes including:
     * - Identification (id, tenant_id)
     * - Hierarchy (rank: 1=highest, ascending=lower)
     * - Details (name, description, color)
     * - Status (is_active)
     * - Relationships (employees_count)
     * - Timestamps (created_at, updated_at, deleted_at for soft deletes)
     *
     * @param  Request  $request  The incoming request
     * @return array<string, mixed> The transformed resource
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'rank' => $this->rank,
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'employees_count' => $this->employees_count,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
