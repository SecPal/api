<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Resources;

use App\Models\OnboardingFormTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * OnboardingFormTemplateResource transforms OnboardingFormTemplate models into API responses.
 *
 * Includes form schema for dynamic rendering.
 *
 * @mixin \App\Models\OnboardingFormTemplate
 */
class OnboardingFormTemplateResource extends JsonResource
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
        /** @var OnboardingFormTemplate $template */
        $template = $this->resource;

        $localizedTemplate = $template->getAttribute(OnboardingFormTemplate::LOCALIZED_TEMPLATE_ATTRIBUTE);

        if (! is_array($localizedTemplate)) {
            $localizedTemplate = [
                'name' => $template->name,
                'description' => $template->description,
                'form_schema' => is_array($template->form_schema) ? $template->form_schema : [],
            ];
        }

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'template_key' => $this->template_key,
            'name' => $localizedTemplate['name'],
            'description' => $localizedTemplate['description'],
            'form_schema' => $localizedTemplate['form_schema'],
            'is_required' => $this->is_required,
            'is_system_template' => $this->is_system_template,
            'sort_order' => $this->sort_order,
            'can_be_deleted' => $this->can_be_deleted,
            'can_be_edited' => $this->can_be_edited,
            'created_at' => \App\Support\ApiTimestamp::nullable($this->created_at),
            'updated_at' => \App\Support\ApiTimestamp::nullable($this->updated_at),
        ];
    }
}
