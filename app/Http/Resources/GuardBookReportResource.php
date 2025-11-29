<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for GuardBookReport model.
 *
 * Transforms GuardBookReport model data for API responses.
 *
 * @mixin \App\Models\GuardBookReport
 */
class GuardBookReportResource extends JsonResource
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
            'report_number' => $this->report_number,
            'period_start' => $this->period_start->toIso8601String(),
            'period_end' => $this->period_end->toIso8601String(),
            'filter_criteria' => $this->filter_criteria,
            'total_events' => $this->total_events,
            'generated_at' => $this->generated_at->toIso8601String(),
            'generated_by' => new UserResource($this->whenLoaded('generatedBy')),
            'guard_book' => new GuardBookResource($this->whenLoaded('guardBook')),
            'report_data' => $this->when($request->routeIs('guard-book-reports.show'), $this->report_data),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
