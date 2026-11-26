<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The functional owner of tenant data.
 *
 * @property int $id
 * @property-read TenantKey|null $key
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    public static function parseId(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($id) ? $id : null;
    }

    /** @return HasOne<TenantKey, $this> */
    public function key(): HasOne
    {
        return $this->hasOne(TenantKey::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /** @return HasMany<ServiceBooking, $this> */
    public function serviceBookings(): HasMany
    {
        return $this->hasMany(ServiceBooking::class);
    }

    /** @return HasMany<InternalCostCenter, $this> */
    public function internalCostCenters(): HasMany
    {
        return $this->hasMany(InternalCostCenter::class);
    }

    /** @return HasMany<CostCenterAllocation, $this> */
    public function costCenterAllocations(): HasMany
    {
        return $this->hasMany(CostCenterAllocation::class);
    }
}
