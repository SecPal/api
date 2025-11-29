<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ObjectArea model representing segmented areas within a physical object.
 *
 * Large objects like airports, industrial sites, or shopping centers need
 * to be divided into areas with optional separate guard books. This enables
 * fine-grained access control and area-specific reporting.
 *
 * Examples:
 * - Airport: "Terminal 1", "Terminal 2", "Vorfeld", "Parkhaus P1"
 * - Shopping Center: "Erdgeschoss", "1. OG", "Tiefgarage", "Außenbereich"
 * - Industrial Site: "Halle A", "Halle B", "Verwaltung", "Außenlager"
 *
 * Key Features:
 * - UUID primary key for distributed ID generation
 * - Soft deletes for data preservation (guard book history)
 * - Optional separate guard book per area
 * - GPS boundaries for geofencing/patrol verification
 * - Extensible metadata for custom attributes
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string $object_id Foreign key to objects
 * @property string $name Display name of the area
 * @property string|null $description Optional description
 * @property bool $requires_separate_guard_book Whether this area has its own guard book
 * @property array<array{lat: float, lon: float}>|null $gps_boundaries Polygon coordinates for geofencing
 * @property array<string, mixed>|null $metadata JSON metadata (floor, access_level, etc.)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read TenantKey $tenant The tenant this area belongs to
 * @property-read SecPalObject $object The parent object
 */
class ObjectArea extends Model
{
    /** @use HasFactory<\Database\Factories\ObjectAreaFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'object_areas';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'object_id',
        'name',
        'description',
        'requires_separate_guard_book',
        'gps_boundaries',
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
            'requires_separate_guard_book' => 'boolean',
            'gps_boundaries' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns this area.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Get the parent object.
     *
     * @return BelongsTo<SecPalObject, $this>
     */
    public function object(): BelongsTo
    {
        return $this->belongsTo(SecPalObject::class, 'object_id');
    }

    /**
     * Check if this area has its own guard book.
     *
     * Alias for the requires_separate_guard_book attribute
     * for better code readability.
     */
    public function hasSeparateGuardBook(): bool
    {
        return $this->requires_separate_guard_book;
    }

    /**
     * Get the customer that owns this area's parent object.
     *
     * Convenience accessor to traverse the object → customer relationship.
     */
    public function getCustomerAttribute(): ?Customer
    {
        return $this->object->customer;
    }
}
