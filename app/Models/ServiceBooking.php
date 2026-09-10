<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingUnit;
use App\Enums\InvoiceState;
use App\Enums\ServiceBookingStatus;
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
 * @property string $contract_id
 * @property \Illuminate\Support\Carbon $service_date
 * @property string $quantity
 * @property BillingUnit $billing_unit
 * @property string $unit_price
 * @property string $currency_code
 * @property string $total
 * @property InvoiceState $invoice_state
 * @property \Illuminate\Support\Carbon|null $invoiced_at
 * @property ServiceBookingStatus $status
 * @property \Illuminate\Support\Carbon|null $retired_at
 * @property-read TenantKey $tenant
 * @property-read Contract $contract
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CostCenterAllocation> $costCenterAllocations
 */
class ServiceBooking extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceBookingFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'contract_id',
        'service_date',
        'quantity',
        'billing_unit',
        'unit_price',
        'currency_code',
        'invoice_state',
        'invoiced_at',
        'status',
        'retired_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'service_date' => 'immutable_date',
            'quantity' => 'decimal:4',
            'billing_unit' => BillingUnit::class,
            'unit_price' => 'decimal:4',
            'total' => 'decimal:2',
            'invoice_state' => InvoiceState::class,
            'invoiced_at' => 'immutable_datetime',
            'status' => ServiceBookingStatus::class,
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return HasMany<CostCenterAllocation, $this> */
    public function costCenterAllocations(): HasMany
    {
        return $this->hasMany(CostCenterAllocation::class);
    }

    /**
     * @param  Builder<ServiceBooking>  $query
     * @return Builder<ServiceBooking>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @param  Builder<ServiceBooking>  $query
     * @return Builder<ServiceBooking>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ServiceBookingStatus::Active);
    }

    /**
     * @param  Builder<ServiceBooking>  $query
     * @return Builder<ServiceBooking>
     */
    public function scopeRetired(Builder $query): Builder
    {
        return $query->where('status', ServiceBookingStatus::Retired);
    }

    /**
     * @param  Builder<ServiceBooking>  $query
     * @return Builder<ServiceBooking>
     */
    public function scopeUnbilled(Builder $query): Builder
    {
        return $query->where('invoice_state', InvoiceState::Unbilled);
    }

    /**
     * @param  Builder<ServiceBooking>  $query
     * @return Builder<ServiceBooking>
     */
    public function scopeInvoiced(Builder $query): Builder
    {
        return $query->where('invoice_state', InvoiceState::Invoiced);
    }
}
