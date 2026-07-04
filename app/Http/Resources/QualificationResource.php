<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * QualificationResource transforms Qualification models into API responses.
 *
 * Represents both system-wide and tenant-specific custom qualifications.
 *
 * @mixin \App\Models\Qualification
 */
class QualificationResource extends JsonResource
{
    /**
     * Disable wrapping for single resources.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'requires_renewal' => $this->requires_renewal,
            'renewal_period_months' => $this->renewal_period_months,
            'is_mandatory' => $this->is_mandatory,
            'is_system_qualification' => $this->is_system_qualification,
            'sort_order' => $this->sort_order,
            'created_at' => \App\Support\ApiTimestamp::nullable($this->created_at),
            'updated_at' => \App\Support\ApiTimestamp::nullable($this->updated_at),
        ];
    }
}
