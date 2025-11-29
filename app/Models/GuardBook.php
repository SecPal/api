<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * GuardBook model representing continuous event stream containers.
 *
 * Guard books are NOT closed physical books but continuous event streams.
 * Reports can be generated from events for any time period on-demand.
 *
 * Critical Design Decision (from ADR-007):
 * - Guard book belongs to EITHER an Object (object-wide) OR ObjectArea (area-specific)
 * - Never both (XOR constraint enforced at database and model level)
 *
 * Examples:
 * - Object-wide: "Wachbuch Rewe Markt Hamburg" - covers entire store
 * - Area-specific: "Wachbuch Terminal 1" - covers only Terminal 1 at airport
 *
 * Key Features:
 * - UUID primary key for distributed ID generation
 * - Soft deletes for data preservation
 * - Archiving via is_active flag (not deletion)
 * - XOR constraint: object_id OR object_area_id
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string|null $object_id Foreign key to objects (if object-wide)
 * @property string|null $object_area_id Foreign key to object_areas (if area-specific)
 * @property string $title Display name of the guard book
 * @property string|null $description Optional description
 * @property bool $is_active Whether the guard book is active
 * @property \Illuminate\Support\Carbon|null $archived_at When the guard book was archived
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read TenantKey $tenant The tenant this guard book belongs to
 * @property-read SecPalObject|null $object The object (if object-wide)
 * @property-read ObjectArea|null $objectArea The object area (if area-specific)
 * @property-read \Illuminate\Database\Eloquent\Collection<int, GuardBookReport> $reports Generated reports
 */
class GuardBook extends Model
{
    /** @use HasFactory<\Database\Factories\GuardBookFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'guard_books';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'object_id',
        'object_area_id',
        'title',
        'description',
        'is_active',
        'archived_at',
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
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Boot the model and register event handlers.
     */
    protected static function booted(): void
    {
        // Validate XOR constraint before creating
        static::creating(function (GuardBook $guardBook): void {
            $guardBook->validateXorConstraint();
        });

        // Validate XOR constraint before updating
        static::updating(function (GuardBook $guardBook): void {
            $guardBook->validateXorConstraint();
        });
    }

    /**
     * Validate the XOR constraint: object_id OR object_area_id, but not both.
     *
     * @throws InvalidArgumentException
     */
    protected function validateXorConstraint(): void
    {
        $hasObject = $this->object_id !== null;
        $hasArea = $this->object_area_id !== null;

        if ($hasObject && $hasArea) {
            throw new InvalidArgumentException(
                'GuardBook must have EITHER object_id OR object_area_id, not both'
            );
        }

        if (! $hasObject && ! $hasArea) {
            throw new InvalidArgumentException(
                'GuardBook must have EITHER object_id OR object_area_id'
            );
        }
    }

    /**
     * Get the tenant that owns this guard book.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Get the object this guard book belongs to (if object-wide).
     *
     * @return BelongsTo<SecPalObject, $this>
     */
    public function object(): BelongsTo
    {
        return $this->belongsTo(SecPalObject::class, 'object_id');
    }

    /**
     * Get the object area this guard book belongs to (if area-specific).
     *
     * @return BelongsTo<ObjectArea, $this>
     */
    public function objectArea(): BelongsTo
    {
        return $this->belongsTo(ObjectArea::class, 'object_area_id');
    }

    /**
     * Get all reports generated from this guard book.
     *
     * @return HasMany<GuardBookReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(GuardBookReport::class, 'guard_book_id');
    }

    /**
     * Check if this guard book is area-specific.
     *
     * Area-specific guard books belong to a specific ObjectArea within an Object.
     * Object-wide guard books cover the entire Object.
     */
    public function isAreaSpecific(): bool
    {
        return $this->object_area_id !== null;
    }

    /**
     * Get the parent object (whether direct or via area).
     *
     * For object-wide guard books: Returns the object directly.
     * For area-specific guard books: Returns the object that the area belongs to.
     *
     * Note: Due to XOR constraint, exactly one of object or objectArea is guaranteed to exist.
     * PHPStan assertions help validate this at static analysis time.
     */
    public function getParentObject(): SecPalObject
    {
        if ($this->isAreaSpecific()) {
            // Guard book belongs to an area, traverse to get the object
            $objectArea = $this->objectArea;
            assert($objectArea !== null, 'ObjectArea must exist for area-specific guard book');

            return $objectArea->object;
        }

        // Guard book belongs directly to the object
        $object = $this->object;
        assert($object !== null, 'Object must exist for object-wide guard book');

        return $object;
    }

    /**
     * Archive this guard book (deactivate without deletion).
     *
     * Archived guard books remain in the database for historical queries
     * but are marked as inactive and cannot receive new events.
     */
    public function archive(): void
    {
        $this->update([
            'is_active' => false,
            'archived_at' => now(),
        ]);
    }

    /**
     * Reactivate a previously archived guard book.
     */
    public function reactivate(): void
    {
        $this->update([
            'is_active' => true,
            'archived_at' => null,
        ]);
    }
}
