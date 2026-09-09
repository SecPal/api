<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $tenant_id
 * @property-read TenantKey $tenant
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WorkInstructionTemplateTranslation> $translations
 */
class WorkInstructionTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\WorkInstructionTemplateFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** @var list<string> */
    protected $fillable = ['tenant_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tenant_id' => 'integer'];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /** @return HasMany<WorkInstructionTemplateTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(WorkInstructionTemplateTranslation::class);
    }
}
