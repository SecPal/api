<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Leadership Level Model.
 *
 * Represents a tenant-specific leadership level definition used for hierarchical
 * access control (ADR-009: Leadership-Based Access Control).
 *
 * Leadership levels enable:
 * - Hierarchical employee visibility (subordinates only, not peers/superiors)
 * - Tenant-configurable ranks (e.g., CEO=1, Branch Director=2, Site Manager=3)
 * - Command chain and escalation paths (BewachV § 9 compliance)
 * - Permission assignment with rank-based filtering
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys table
 * @property int $rank Numerical hierarchy (1=CEO, ascending for lower levels)
 * @property string $name Display name (e.g., "Managing Director", "Site Manager")
 * @property string|null $description Optional detailed description
 * @property string|null $color Hex color for UI (e.g., "#FF5733")
 * @property bool $is_active Whether this level is currently in use
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read TenantKey $tenant The tenant this level belongs to
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Employee> $employees Employees assigned to this level
 *
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/424 Issue #424: Leadership Levels Backend API
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251221-inheritance-blocking-and-leadership-access-control.md
 */
final class LeadershipLevel extends Model
{
    /** @use HasFactory<\Database\Factories\LeadershipLevelFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'leadership_levels';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'rank',
        'name',
        'description',
        'color',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'rank' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [];

    /**
     * Get the tenant that owns this leadership level.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Get all employees assigned to this leadership level.
     *
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'leadership_level_id');
    }

    /**
     * Scope a query to only include active leadership levels.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<LeadershipLevel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<LeadershipLevel>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to order by rank (ascending).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<LeadershipLevel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<LeadershipLevel>
     */
    public function scopeOrderByRank($query)
    {
        return $query->orderBy('rank', 'asc');
    }

    /**
     * Check if this leadership level can be deleted.
     *
     * A leadership level can only be deleted if no employees are assigned to it.
     */
    public function canBeDeleted(): bool
    {
        return $this->employees()->count() === 0;
    }

    /**
     * Get the count of employees assigned to this leadership level.
     *
     * This accessor checks for cached count or loaded relation to avoid N+1 queries.
     * When used in collections, prefer withCount('employees') on the query builder.
     */
    public function getEmployeesCountAttribute(): int
    {
        // Return cached count if available (from withCount)
        if (array_key_exists('employees_count', $this->attributes)) {
            /** @var int|string|null $value */
            $value = $this->attributes['employees_count'];

            return (int) $value;
        }

        // Return count from loaded relation if available
        if ($this->relationLoaded('employees')) {
            $count = $this->employees->count();
            $this->attributes['employees_count'] = $count;

            return $count;
        }

        // Last resort: query database and cache result
        $count = $this->employees()->count();
        $this->attributes['employees_count'] = $count;

        return $count;
    }
}
