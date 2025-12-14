<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Site model representing physical locations where services are provided.
 *
 * Sites are the physical locations (objects/facilities) where security services
 * are provided. Each site belongs to exactly one customer and is managed by one
 * internal organizational unit. Sites can be permanent (ongoing contracts) or
 * temporary (event-based).
 *
 * Key Features:
 * - UUID primary key for distributed ID generation
 * - Auto-generated site_number (format: OBJ-YYYY-NNNN)
 * - Soft deletes for data preservation
 * - Flexible user assignments with roles
 * - GPS coordinates for geofencing
 * - Validity period for temporary sites
 * - Cost center management
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string $customer_id Foreign key to customers
 * @property string $organizational_unit_id Foreign key to organizational_units
 * @property string $site_number Auto-generated unique identifier (e.g., OBJ-2025-0001)
 * @property string $name Site name (e.g., "Airport Terminal 1")
 * @property string $type Type: 'permanent' or 'temporary'
 * @property array<string, mixed> $address Physical address with GPS coordinates
 * @property array<string, mixed>|null $contact On-site contact person
 * @property string|null $access_instructions How to access the site
 * @property string|null $notes Internal notes
 * @property array<string, mixed>|null $metadata Extensible metadata
 * @property bool $is_active Active status
 * @property \Illuminate\Support\Carbon|null $valid_from Contract start date
 * @property \Illuminate\Support\Carbon|null $valid_until Contract end date
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read string $full_address Formatted full address string
 * @property-read bool $is_expired Whether the site validity period has expired
 * @property-read TenantKey $tenant The tenant this site belongs to
 * @property-read Customer $customer The customer that owns this site
 * @property-read OrganizationalUnit $organizationalUnit The internal unit responsible
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $assignments User assignments to this site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $assignedUsers Users assigned to this site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $costCenters Cost centers for this site
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#310 Customer and Site Eloquent models
 */
class Site extends Model
{
    /** @use HasFactory<\Database\Factories\SiteFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sites';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'customer_id',
        'organizational_unit_id',
        'site_number',
        'name',
        'type',
        'address',
        'contact',
        'access_instructions',
        'notes',
        'metadata',
        'is_active',
        'valid_from',
        'valid_until',
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
            'address' => 'array',
            'contact' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns this site.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Get the customer that owns this site.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Get the organizational unit responsible for this site.
     *
     * @return BelongsTo<OrganizationalUnit, $this>
     */
    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id');
    }

    /**
     * Get all assignments for this site.
     *
     * @return HasMany<SiteAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(SiteAssignment::class, 'site_id');
    }

    /**
     * Get all users assigned to this site.
     *
     * Many-to-many relationship through site_assignments table.
     *
     * @return BelongsToMany<User, $this>
     */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'site_assignments', 'site_id', 'user_id')
            ->withPivot(['role', 'valid_from', 'valid_until', 'notes'])
            ->withTimestamps();
    }

    /**
     * Get all cost centers for this site.
     *
     * @return HasMany<CostCenter, $this>
     */
    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class, 'site_id');
    }

    /**
     * Scope a query to only include active sites.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include permanent sites.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopePermanent(Builder $query): Builder
    {
        return $query->where('type', 'permanent');
    }

    /**
     * Scope a query to only include temporary sites.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeTemporary(Builder $query): Builder
    {
        return $query->where('type', 'temporary');
    }

    /**
     * Scope a query to only include sites that are currently valid.
     *
     * A site is considered currently valid if:
     * - valid_from is NULL or in the past
     * - valid_until is NULL or in the future
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeCurrentlyValid(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('valid_from')
                ->orWhere('valid_from', '<=', now());
        })->where(function (Builder $q): void {
            $q->whereNull('valid_until')
                ->orWhere('valid_until', '>=', now());
        });
    }

    /**
     * Scope a query to filter by organizational unit.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeForOrganizationalUnit(Builder $query, string $unitId): Builder
    {
        return $query->where('organizational_unit_id', $unitId);
    }

    /**
     * Generate a unique site number for the given tenant.
     *
     * Format: OBJ-YYYY-NNNN
     * Where:
     * - OBJ = Objekt (German for object/site)
     * - YYYY = Current year
     * - NNNN = 4-digit sequential number within the year
     *
     * Example: OBJ-2025-0001, OBJ-2025-0002, ...
     *
     * The method searches for the highest existing number in the current year
     * (including soft-deleted records to prevent number reuse) and increments it.
     * If no sites exist for the year, starts with 0001.
     *
     * Uses database row-level locking to prevent race conditions during concurrent
     * site creation.
     */
    public static function generateSiteNumber(int $tenantId): string
    {
        return DB::transaction(function () use ($tenantId) {
            $year = now()->year;

            /** @var self|null $latest */
            $latest = self::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('site_number', 'like', "OBJ-{$year}-%")
                ->orderBy('site_number', 'desc')
                ->lockForUpdate()
                ->first();

            $sequence = $latest !== null
                ? ((int) substr($latest->site_number, -4)) + 1
                : 1;

            return sprintf('OBJ-%d-%04d', $year, $sequence);
        });
    }

    /**
     * Get the full formatted address as a string.
     *
     * Combines street, postal_code, and city from the address array
     * into a single comma-separated string.
     *
     * @return Attribute<string, never>
     */
    protected function fullAddress(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if (! is_array($this->address)) {
                    return '';
                }

                $parts = array_filter([
                    $this->address['street'] ?? null,
                    $this->address['postal_code'] ?? null,
                    $this->address['city'] ?? null,
                ]);

                return implode(', ', $parts);
            }
        );
    }

    /**
     * Check if the site validity period has expired.
     *
     * A site is considered expired if:
     * - valid_until is set AND is in the past
     *
     * @return Attribute<bool, never>
     */
    protected function isExpired(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->valid_until !== null && $this->valid_until->isPast()
        );
    }
}
