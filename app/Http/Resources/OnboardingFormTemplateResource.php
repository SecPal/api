<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use App\Models\OnboardingFormTemplate;
use App\Services\OnboardingSchemaLocalizationService;
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

        $localizedTemplate = app(OnboardingSchemaLocalizationService::class)
            ->localizeTemplate($template, $this->resolveLocale($request));

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $localizedTemplate['name'],
            'description' => $localizedTemplate['description'],
            'form_schema' => $localizedTemplate['form_schema'],
            'is_required' => $this->is_required,
            'is_system_template' => $this->is_system_template,
            'sort_order' => $this->sort_order,
            'can_be_deleted' => $this->can_be_deleted,
            'can_be_edited' => $this->can_be_edited,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function resolveLocale(Request $request): string
    {
        $preferredLocale = $request->user()?->preferred_locale;

        if (is_string($preferredLocale) && in_array($preferredLocale, OnboardingSchemaLocalizationService::SUPPORTED_LOCALES, true)) {
            return $preferredLocale;
        }

        $requestLocale = $request->getPreferredLanguage(OnboardingSchemaLocalizationService::SUPPORTED_LOCALES);

        return is_string($requestLocale) ? $requestLocale : OnboardingSchemaLocalizationService::DEFAULT_LOCALE;
    }
}
