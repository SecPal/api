<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Site Assignment model for flexible user-to-site role assignments.
 *
 * This model represents the flexible assignment of internal users to sites
 * with customizable role names. Each assignment can have a validity period for
 * historical tracking and temporary assignments. This allows organizations to
 * use their own terminology (e.g., "Account Manager", "Site Manager", "Operations Lead").
 *
 * Key Features:
 * - UUID primary key for distributed ID generation
 * - Flexible role field (tenant-specific terminology)
 * - Temporal validity tracking (valid_from/valid_until)
 * - No soft deletes (assignments are historical records)
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string $site_id Foreign key to sites
 * @property string $user_id Foreign key to users
 * @property string $role Flexible role name (tenant-specific)
 * @property \Illuminate\Support\Carbon|null $valid_from When assignment starts
 * @property \Illuminate\Support\Carbon|null $valid_until When assignment ends (null = indefinite)
 * @property string|null $notes Internal notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read bool $is_active Whether assignment is currently active
 * @property-read TenantKey $tenant The tenant this assignment belongs to
 * @property-read Site $site The site for this assignment
 * @property-read User $user The user assigned to the site
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#311 Assignment models
 */
class SiteAssignment extends Model
{
    /** @use HasFactory<\Database\Factories\SiteAssignmentFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'site_assignments';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'site_id',
        'user_id',
        'role',
        'valid_from',
        'valid_until',
        'notes',
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
            'valid_from' => 'date',
            'valid_until' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns this assignment.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Get the site for this assignment.
     *
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /**
     * Get the user assigned to the site.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope a query to only include assignments for a specific user.
     *
     * @param  Builder<SiteAssignment>  $query
     * @return Builder<SiteAssignment>
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include assignments with a specific role.
     *
     * @param  Builder<SiteAssignment>  $query
     * @return Builder<SiteAssignment>
     */
    public function scopeForRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    /**
     * Scope a query to only include assignments for a specific tenant.
     *
     * @param  Builder<SiteAssignment>  $query
     * @return Builder<SiteAssignment>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include currently active assignments.
     *
     * Considers both valid_from and valid_until dates. An assignment is active if:
     * - valid_from is null OR valid_from <= today
     * - valid_until is null OR valid_until >= today
     *
     * @param  Builder<SiteAssignment>  $query
     * @return Builder<SiteAssignment>
     */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $today = now()->startOfDay();

        return $query->where(function (Builder $q) use ($today): void {
            $q->whereNull('valid_from')
                ->orWhere('valid_from', '<=', $today);
        })->where(function (Builder $q) use ($today): void {
            $q->whereNull('valid_until')
                ->orWhere('valid_until', '>=', $today);
        });
    }

    /**
     * Get whether this assignment is currently active.
     *
     * @return Attribute<bool, never>
     */
    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: function (): bool {
                $now = now()->startOfDay();

                if ($this->valid_from && $this->valid_from->greaterThan($now)) {
                    return false;
                }

                if ($this->valid_until && $this->valid_until->lessThan($now)) {
                    return false;
                }

                return true;
            }
        );
    }
}
