<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EmployeeQualificationResource transforms EmployeeQualification pivot models into API responses.
 *
 * Includes certificate details and expiry tracking.
 *
 * @mixin \App\Models\EmployeeQualification
 */
class EmployeeQualificationResource extends JsonResource
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
            'employee_id' => $this->employee_id,
            'qualification_id' => $this->qualification_id,
            'obtained_date' => $this->obtained_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'certificate_number' => $this->certificate_number,
            'issuing_authority' => $this->issuing_authority,
            'notes' => $this->notes,
            'document_path' => $this->document_path,
            'status' => $this->status,

            // Relationships (optional)
            'qualification' => new QualificationResource($this->whenLoaded('qualification')),
            'employee' => new EmployeeResource($this->whenLoaded('employee')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
