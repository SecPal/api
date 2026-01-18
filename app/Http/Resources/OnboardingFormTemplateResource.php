<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * OnboardingFormTemplateResource transforms OnboardingFormTemplate models into API responses.
 *
 * Includes form schema for dynamic rendering.
 * Supports localized schemas passed from controller.
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
     * Localized schema (if provided by controller).
     *
     * @var array<string, mixed>|null
     */
    private ?array $localizedSchema = null;

    /**
     * Constructor to accept optional localized schema.
     *
     * @param  mixed  $resource  The underlying model
     * @param  array<string, mixed>|null  $localizedSchema  Optional localized schema
     */
    public function __construct($resource, ?array $localizedSchema = null)
    {
        parent::__construct($resource);
        $this->localizedSchema = $localizedSchema;
    }

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
            'form_schema' => $this->localizedSchema ?? $this->form_schema,
            'is_required' => $this->is_required,
            'is_system_template' => $this->is_system_template,
            'sort_order' => $this->sort_order,
            'can_be_deleted' => $this->can_be_deleted,
            'can_be_edited' => $this->can_be_edited,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
