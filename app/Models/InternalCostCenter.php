<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Enums\InternalCostCenterStatus;
use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property InternalCostCenterStatus $status
 * @property \Illuminate\Support\Carbon|null $inactive_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read TenantKey $tenant
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CostCenterAllocation> $costCenterAllocations
 */
class InternalCostCenter extends Model
{
    /** @var list<string> */
    public const CREATE_FIELDS = ['code', 'name'];

    /** @var list<string> */
    public const MUTABLE_BUSINESS_FIELDS = ['name'];

    /** @use HasFactory<\Database\Factories\InternalCostCenterFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'status',
        'inactive_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'status' => InternalCostCenterStatus::class,
            'inactive_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /** @return HasMany<CostCenterAllocation, $this> */
    public function costCenterAllocations(): HasMany
    {
        return $this->hasMany(CostCenterAllocation::class);
    }

    /**
     * @param  Builder<InternalCostCenter>  $query
     * @return Builder<InternalCostCenter>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @param  Builder<InternalCostCenter>  $query
     * @return Builder<InternalCostCenter>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', InternalCostCenterStatus::Active);
    }

    /**
     * @param  Builder<InternalCostCenter>  $query
     * @return Builder<InternalCostCenter>
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('status', InternalCostCenterStatus::Inactive);
    }
}
