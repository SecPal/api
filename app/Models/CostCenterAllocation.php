<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $service_booking_id
 * @property string $internal_cost_center_id
 * @property int $share_bps
 * @property-read TenantKey $tenant
 * @property-read ServiceBooking $serviceBooking
 * @property-read InternalCostCenter $internalCostCenter
 */
class CostCenterAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\CostCenterAllocationFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'service_booking_id',
        'internal_cost_center_id',
        'share_bps',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'share_bps' => 'integer',
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /** @return BelongsTo<ServiceBooking, $this> */
    public function serviceBooking(): BelongsTo
    {
        return $this->belongsTo(ServiceBooking::class);
    }

    /** @return BelongsTo<InternalCostCenter, $this> */
    public function internalCostCenter(): BelongsTo
    {
        return $this->belongsTo(InternalCostCenter::class);
    }

    /**
     * @param  Builder<CostCenterAllocation>  $query
     * @return Builder<CostCenterAllocation>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
