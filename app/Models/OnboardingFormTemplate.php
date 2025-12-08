<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
 * @property string|null $description
 * @property array $form_schema
 * @property bool $is_required
 * @property bool $is_system_template
 * @property int $sort_order
 */
class OnboardingFormTemplate extends Model
{
    use HasUuids, SoftDeletes;

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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(OnboardingFormSubmission::class, 'form_template_id');
    }
}
