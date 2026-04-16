<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Customer model representing external customer organizations.
 *
 * This model represents external organizations that the security service
 * company provides services to. Customers have a flat (non-hierarchical)
 * structure and are associated with sites where services are provided.
 *
 * Key Features:
 * - UUID primary key for distributed ID generation
 * - Auto-generated customer_number (format: KD-YYYY-NNNN)
 * - Soft deletes for data preservation
 * - Flexible user assignments with roles
 * - Structured billing address and contact information
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string $customer_number Auto-generated unique identifier (e.g., KD-2025-0001)
 * @property string $name Company/Organization name
 * @property array<string, mixed> $billing_address Structured billing address
 * @property array<string, mixed>|null $contact Primary contact person information
 * @property string|null $notes Internal notes
 * @property array<string, mixed>|null $metadata Extensible metadata for custom attributes
 * @property bool $is_active Active status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read TenantKey $tenant The tenant this customer belongs to
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Site> $sites Sites belonging to this customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Model> $assignments User assignments to this customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $assignedUsers Users assigned to this customer
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#310 Customer and Site Eloquent models
 */
class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids, LogsActivity, SoftDeletes {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'customers';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'customer_number',
        'name',
        'billing_address',
        'contact',
        'notes',
        'metadata',
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
            'billing_address' => 'array',
            'contact' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Configure activity logging.
     *
     * Logs customer changes (8-year retention: customer_changes).
     * Tracks: customer_number, name, billing_address, contact, is_active.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'customer_number',
                'name',
                'billing_address',
                'contact',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('customer_changes');
    }

    /**
     * Get the tenant that owns this customer.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Get all sites belonging to this customer.
     *
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'customer_id');
    }

    /**
     * Get all assignments for this customer.
     *
     * @return HasMany<CustomerAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CustomerAssignment::class, 'customer_id');
    }

    /**
     * Get all users assigned to this customer.
     *
     * Many-to-many relationship through customer_assignments table.
     *
     * @return BelongsToMany<User, $this>
     */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_assignments', 'customer_id', 'user_id')
            ->withPivot(['role', 'valid_from', 'valid_until', 'notes'])
            ->withTimestamps();
    }

    /**
     * Scope a query to only include active customers.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by tenant.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Generate a unique customer number for the given tenant.
     *
     * Format: KD-YYYY-NNNN
     * Where:
     * - KD = Kunde (German for customer)
     * - YYYY = Current year
     * - NNNN = 4-digit sequential number within the year
     *
     * Example: KD-2025-0001, KD-2025-0002, ...
     *
     * The method searches for the highest existing number in the current year
     * (including soft-deleted records to prevent number reuse) and increments it.
     * If no customers exist for the year, starts with 0001.
     *
     * Callers that persist a new customer must keep number generation and insert
     * inside the same tenant-scoped transaction to avoid concurrent duplicates.
     */
    public static function generateCustomerNumber(int $tenantId): string
    {
        return DB::transaction(function () use ($tenantId) {
            $year = now()->year;

            /** @var self|null $latest */
            $latest = self::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('customer_number', 'like', "KD-{$year}-%")
                ->orderBy('customer_number', 'desc')
                ->lockForUpdate()
                ->first();

            $sequence = $latest !== null
                ? ((int) substr($latest->customer_number, -4)) + 1
                : 1;

            return sprintf('KD-%d-%04d', $year, $sequence);
        });
    }
}
