<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * OnboardingFormTemplate model.
 *
 * @property string $id
 * @property int|null $tenant_id
 * @property string $name
 * @property array<string, mixed>|null $form_schema JSON schema for the form
 * @property string|null $description
 * @property bool $is_required
 * @property bool $is_system_template
 * @property int $sort_order
 */
class OnboardingFormTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\OnboardingFormTemplateFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids, SoftDeletes {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'form_schema',
        'is_required',
        'is_system_template',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'form_schema' => 'array',
            'is_required' => 'boolean',
            'is_system_template' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * @return HasMany<OnboardingFormSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(OnboardingFormSubmission::class, 'form_template_id');
    }

    /**
     * Determine if the template can be deleted.
     *
     * System templates cannot be deleted to protect BewachV § 16 compliance.
     */
    public function getCanBeDeletedAttribute(): bool
    {
        return ! $this->is_system_template;
    }

    /**
     * Determine if the template can be edited.
     *
     * System templates cannot be edited to protect BewachV § 16 compliance.
     */
    public function getCanBeEditedAttribute(): bool
    {
        return ! $this->is_system_template;
    }

    /**
     * System templates remain globally addressable alongside tenant-local ones.
     */
    protected function routeBindingAllowsGlobalRecords(): bool
    {
        return true;
    }
}
