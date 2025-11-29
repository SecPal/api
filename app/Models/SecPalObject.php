<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SecPalObject model representing physical locations/sites managed by customers.
 *
 * Objects are the physical locations where security services are provided.
 * Each object belongs to exactly one customer and may have multiple areas
 * with separate guard books.
 *
 * Note: Named "SecPalObject" instead of "Object" to avoid conflicts with PHP's
 * reserved "object" type hint in PHP 7.2+.
 *
 * Key Features:
 * - UUID primary key for distributed ID generation
 * - Soft deletes for data preservation (guard book history)
 * - GPS coordinates for geofencing and map display
 * - Extensible metadata for custom attributes
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string $customer_id Foreign key to customers
 * @property string $object_number Per-tenant unique identifier (e.g., "OBJ-REWE-HH-001")
 * @property string $name Display name of the object
 * @property string $address Full address of the location
 * @property array{lat: float, lon: float}|null $gps_coordinates GPS coordinates for geofencing
 * @property array<string, mixed>|null $metadata JSON metadata (floors, emergency_contacts, etc.)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read TenantKey $tenant The tenant this object belongs to
 * @property-read Customer $customer The customer that owns this object
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ObjectArea> $areas Object areas for segmentation
 */
class SecPalObject extends Model
{
    /** @use HasFactory<\Database\Factories\SecPalObjectFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'objects';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'customer_id',
        'object_number',
        'name',
        'address',
        'gps_coordinates',
        'metadata',
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
            'gps_coordinates' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns this object.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Get the customer that owns this object.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Get all areas of this object.
     *
     * @return HasMany<ObjectArea, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(ObjectArea::class, 'object_id');
    }

    /**
     * Get the primary guard book for this object.
     *
     * The primary guard book is for the entire object (not area-specific).
     * Returns null if no primary guard book exists yet.
     *
     * @return HasOne<GuardBook, $this>
     */
    public function primaryGuardBook(): HasOne
    {
        return $this->hasOne(GuardBook::class, 'object_id');
    }

    /**
     * Get all guard books for this object (primary + area-specific).
     *
     * @return HasMany<GuardBook, $this>
     */
    public function guardBooks(): HasMany
    {
        return $this->hasMany(GuardBook::class, 'object_id');
    }

    /**
     * Get customer user object access records for this object.
     *
     * @return HasMany<CustomerUserObjectAccess, $this>
     */
    public function userAccesses(): HasMany
    {
        return $this->hasMany(CustomerUserObjectAccess::class, 'object_id');
    }

    /**
     * Check if this object has area-based segmentation.
     */
    public function hasAreaSegmentation(): bool
    {
        return $this->areas()->exists();
    }

    /**
     * Get areas that require separate guard books.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ObjectArea>
     */
    public function areasWithSeparateGuardBooks(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->areas()
            ->where('requires_separate_guard_book', true)
            ->get();
    }
}
