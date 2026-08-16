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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cost Center model for optional billing/accounting integration.
 *
 * Cost centers are optional and used by companies that need detailed
 * billing/accounting integration at the site level. Not all companies
 * use cost centers, so this table may remain empty for some tenants.
 *
 * Key Features:
 * - UUID primary key for distributed ID generation
 * - Belongs to a site (sites can have multiple cost centers)
 * - Code field for customer's internal accounting number
 * - Activity type field for future tariff mapping
 * - Soft deletes to preserve historical references
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string $site_id Foreign key to sites
 * @property string $code Cost center code (e.g., KST-001)
 * @property string $name Descriptive name
 * @property string|null $activity_type Type of activity performed
 * @property string|null $description Detailed description
 * @property bool $is_active Active status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read TenantKey $tenant The tenant this cost center belongs to
 * @property-read Site $site The site this cost center belongs to
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#311 Assignment models
 */
class CostCenter extends Model
{
    /** @use HasFactory<\Database\Factories\CostCenterFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids, SoftDeletes {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cost_centers';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'site_id',
        'code',
        'name',
        'activity_type',
        'description',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns this cost center.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Get the site that owns this cost center.
     *
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /**
     * Scope a query to only include active cost centers.
     *
     * @param  Builder<CostCenter>  $query
     * @return Builder<CostCenter>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include cost centers for a specific tenant.
     *
     * @param  Builder<CostCenter>  $query
     * @return Builder<CostCenter>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include cost centers with a specific activity type.
     *
     * @param  Builder<CostCenter>  $query
     * @return Builder<CostCenter>
     */
    public function scopeForActivityType(Builder $query, string $type): Builder
    {
        return $query->where('activity_type', $type);
    }

    /**
     * Scope a query to only include cost centers for a specific site.
     *
     * @param  Builder<CostCenter>  $query
     * @return Builder<CostCenter>
     */
    public function scopeForSite(Builder $query, string $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }
}
