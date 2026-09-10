<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingUnit;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
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
 * @property string $customer_id
 * @property ContractType $type
 * @property ContractStatus $status
 * @property \Illuminate\Support\Carbon $starts_on
 * @property \Illuminate\Support\Carbon|null $ends_on
 * @property BillingUnit $billing_unit
 * @property string $unit_price
 * @property string $currency_code
 * @property \Illuminate\Support\Carbon|null $retired_at
 * @property-read TenantKey $tenant
 * @property-read Customer $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceBooking> $serviceBookings
 */
class Contract extends Model
{
    /** @use HasFactory<\Database\Factories\ContractFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'customer_id',
        'type',
        'status',
        'starts_on',
        'ends_on',
        'billing_unit',
        'unit_price',
        'currency_code',
        'retired_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'type' => ContractType::class,
            'status' => ContractStatus::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'billing_unit' => BillingUnit::class,
            'unit_price' => 'decimal:4',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return HasMany<ServiceBooking, $this> */
    public function serviceBookings(): HasMany
    {
        return $this->hasMany(ServiceBooking::class);
    }

    /**
     * @param  Builder<Contract>  $query
     * @return Builder<Contract>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @param  Builder<Contract>  $query
     * @return Builder<Contract>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ContractStatus::Active);
    }

    /**
     * @param  Builder<Contract>  $query
     * @return Builder<Contract>
     */
    public function scopeRetired(Builder $query): Builder
    {
        return $query->where('status', ContractStatus::Retired);
    }
}
