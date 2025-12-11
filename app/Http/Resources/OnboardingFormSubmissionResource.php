<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * OnboardingFormSubmissionResource transforms OnboardingFormSubmission models into API responses.
 *
 * Form data is encrypted and should only be returned to authorized users.
 *
 * @mixin \App\Models\OnboardingFormSubmission
 */
class OnboardingFormSubmissionResource extends JsonResource
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
            'form_template_id' => $this->form_template_id,
            'form_data' => $this->form_data,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_notes' => $this->review_notes,

            // Relationships (optional)
            'form_template' => new OnboardingFormTemplateResource($this->whenLoaded('formTemplate')),
            'reviewer' => new UserResource($this->whenLoaded('reviewer')),

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
