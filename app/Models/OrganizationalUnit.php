<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * OrganizationalUnit model representing internal organizational structure.
 *
 * This model represents the security service company's internal hierarchy
 * (holdings, companies, regions, branches, divisions, departments).
 * Uses the Closure Table Pattern for efficient hierarchical queries.
 *
 * Key Features:
 * - UUID primary key for distributed ID generation
 * - Soft deletes for data preservation
 * - Automatic closure table management for hierarchy
 * - Unlimited hierarchy depth support
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string $type Enum: holding, company, region, branch, division, department, custom
 * @property string $name Display name of the organizational unit
 * @property string|null $custom_type_name Custom type name when type='custom'
 * @property string|null $description Optional description
 * @property array<string, mixed>|null $metadata JSON metadata (address, phone, etc.)
 * @property bool $is_legal_entity Independent legal-person status; not derived from hierarchy type
 * @property bool $is_establishment Independent establishment status; not derived from hierarchy type
 * @property bool $is_active Independent administrative status
 * @property bool $is_assignable Independent eligibility for new operational assignments and scopes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read TenantKey $tenant The tenant this unit belongs to
 * @property-read OrganizationalUnit|null $parent The direct parent unit
 * @property-read Collection<int, OrganizationalUnit> $children Direct child units
 * @property-read Collection<int, OrganizationalUnit> $ancestors All ancestor units (ordered by depth, closest first)
 * @property-read Collection<int, OrganizationalUnit> $descendants All descendant units
 */
class OrganizationalUnit extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationalUnitFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids, SoftDeletes {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'organizational_units';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'type',
        'name',
        'custom_type_name',
        'description',
        'metadata',
        'is_legal_entity',
        'is_establishment',
        'is_active',
        'is_assignable',
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
            'metadata' => 'array',
            'is_legal_entity' => 'boolean',
            'is_establishment' => 'boolean',
            'is_active' => 'boolean',
            'is_assignable' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * The "booted" method of the model.
     * Sets up model event observers for closure table management.
     */
    protected static function booted(): void
    {
        // Create self-reference closure entry when unit is created
        static::created(function (OrganizationalUnit $unit): void {
            OrganizationalUnitClosure::firstOrCreate([
                'ancestor_id' => $unit->id,
                'descendant_id' => $unit->id,
            ], [
                'depth' => 0,
            ]);
        });

        static::deleted(function (OrganizationalUnit $unit): void {
            // Keep closure rows during soft deletes so inherited scope resolution
            // remains stable for trashed descendants and stranded employees.
            if (! $unit->trashed()) {
                OrganizationalUnitClosure::where('ancestor_id', $unit->id)
                    ->where('depth', '>', 0)
                    ->delete();
            }
        });

        static::restored(function (OrganizationalUnit $unit): void {
            $unit->ensureSelfClosureExists();

            $parentId = OrganizationalUnitClosure::where('descendant_id', $unit->id)
                ->where('depth', 1)
                ->value('ancestor_id');

            if ($parentId === null) {
                return;
            }

            $hasActiveParent = OrganizationalUnit::query()
                ->whereKey($parentId)
                ->exists();

            if (! $hasActiveParent) {
                $unit->removeParent();
            }
        });

        // Note: Closure table cleanup on force delete is handled by ON DELETE CASCADE
        // in the database migration, so no explicit forceDeleted handler is needed.
    }

    /**
     * Get the tenant that owns this organizational unit.
     *
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * Get the direct parent organizational unit.
     *
     * Uses the closure table to find the ancestor at depth=1.
     * This is an accessor method, not a relationship, because the parent
     * is determined dynamically via the closure table.
     */
    public function getParentAttribute(): ?OrganizationalUnit
    {
        /** @var string|null $parentId */
        $parentId = OrganizationalUnitClosure::where('descendant_id', $this->id)
            ->where('depth', 1)
            ->value('ancestor_id');

        if ($parentId === null) {
            return null;
        }

        /** @var OrganizationalUnit|null */
        return OrganizationalUnit::find($parentId);
    }

    /**
     * Get all direct children of this organizational unit.
     *
     * @return Collection<int, OrganizationalUnit>
     */
    public function getChildrenAttribute(): Collection
    {
        $childIds = OrganizationalUnitClosure::where('ancestor_id', $this->id)
            ->where('depth', 1)
            ->pluck('descendant_id');

        return OrganizationalUnit::whereIn('id', $childIds)->get();
    }

    /**
     * Get the direct parent organizational unit as an Eloquent relationship.
     *
     * Uses the closure table to find the ancestor at depth=1.
     * This is a proper Eloquent relationship (unlike the getParentAttribute accessor)
     * that supports eager loading via with('parent').
     *
     * @return HasOneThrough<OrganizationalUnit, OrganizationalUnitClosure, $this>
     */
    public function parent(): HasOneThrough
    {
        return $this->hasOneThrough(
            OrganizationalUnit::class,
            OrganizationalUnitClosure::class,
            'descendant_id', // Foreign key on closure table
            'id', // Foreign key on organizational_units table
            'id', // Local key on this model
            'ancestor_id' // Local key on closure table
        )->where('organizational_unit_closures.depth', 1);
    }

    /**
     * Get all ancestors of this organizational unit.
     *
     * Returns ancestors ordered by depth (closest first: parent, grandparent, etc.)
     *
     * @return BelongsToMany<OrganizationalUnit, $this>
     */
    public function ancestors(): BelongsToMany
    {
        return $this->belongsToMany(
            OrganizationalUnit::class,
            'organizational_unit_closures',
            'descendant_id',
            'ancestor_id'
        )
            ->wherePivot('depth', '>', 0)
            ->orderByPivot('depth', 'asc')
            ->withPivot('depth');
    }

    /**
     * Get all descendants of this organizational unit.
     *
     * @return BelongsToMany<OrganizationalUnit, $this>
     */
    public function descendants(): BelongsToMany
    {
        return $this->belongsToMany(
            OrganizationalUnit::class,
            'organizational_unit_closures',
            'ancestor_id',
            'descendant_id'
        )
            ->wherePivot('depth', '>', 0)
            ->orderByPivot('depth', 'asc')
            ->withPivot('depth');
    }

    /**
     * Set the parent of this organizational unit.
     *
     * Updates the closure table to reflect the new hierarchy position.
     * If moving from an existing parent, old closure entries are removed first.
     * Wrapped in a database transaction to ensure data consistency.
     *
     * @throws \InvalidArgumentException If setting would create a cycle
     */
    public function setParent(?OrganizationalUnit $parent): void
    {
        if ($parent === null) {
            $this->removeParent();

            return;
        }

        // Prevent setting self as parent (cycle prevention)
        if ($parent->id === $this->id) {
            throw new \InvalidArgumentException('Cannot set unit as its own parent.');
        }

        $this->ensureSelfClosureExists();
        $parent->ensureSelfClosureExists();

        // Get all descendants of this unit (including self) - needed for cycle check
        /** @var list<string> $descendantIds */
        $descendantIds = OrganizationalUnitClosure::where('ancestor_id', $this->id)
            ->pluck('descendant_id')
            ->all();

        // Prevent setting a descendant as parent (cycle prevention)
        if (in_array($parent->id, $descendantIds, true)) {
            throw new \InvalidArgumentException('Cannot set a descendant as parent (would create a cycle).');
        }

        // Wrap in transaction for data consistency
        DB::transaction(function () use ($parent, $descendantIds): void {
            // Remove old ancestor entries (if any) for this unit and all descendants
            $this->removeAncestorClosures();

            // Fetch all depths from this unit to its descendants in one query (N+1 fix)
            /** @var array<string, int> $descendantDepths */
            $descendantDepths = OrganizationalUnitClosure::where('ancestor_id', $this->id)
                ->whereIn('descendant_id', $descendantIds)
                ->pluck('depth', 'descendant_id')
                ->all();

            // Get all ancestors of new parent (including parent itself via depth+1)
            $parentAncestors = OrganizationalUnitClosure::where('descendant_id', $parent->id)
                ->get();

            // Create new closure entries: each ancestor of parent -> each descendant of this unit
            $newClosures = [];
            foreach ($parentAncestors as $parentAncestor) {
                foreach ($descendantIds as $descendantId) {
                    // Lookup depth from pre-fetched array
                    $descendantDepth = $descendantDepths[$descendantId] ?? 0;

                    $newClosures[] = [
                        'ancestor_id' => $parentAncestor->ancestor_id,
                        'descendant_id' => $descendantId,
                        'depth' => $parentAncestor->depth + 1 + $descendantDepth,
                    ];
                }
            }

            if (count($newClosures) > 0) {
                OrganizationalUnitClosure::insert($newClosures);
            }
        });
    }

    /**
     * Remove the parent relationship, making this unit a root.
     *
     * Removes all ancestor closures for this unit and its descendants,
     * keeping only internal subtree relationships.
     */
    public function removeParent(): void
    {
        $this->removeAncestorClosures();
    }

    /**
     * Remove all ancestor closure entries for this unit and its descendants.
     *
     * Preserves the internal subtree relationships (within descendants).
     */
    private function removeAncestorClosures(): void
    {
        $this->ensureSelfClosureExists();

        // Get all descendant IDs (including self)
        $descendantIds = OrganizationalUnitClosure::where('ancestor_id', $this->id)
            ->pluck('descendant_id')
            ->toArray();

        // Delete closures where:
        // - descendant is in our subtree AND
        // - ancestor is NOT in our subtree (i.e., it's an external ancestor)
        OrganizationalUnitClosure::whereIn('descendant_id', $descendantIds)
            ->whereNotIn('ancestor_id', $descendantIds)
            ->delete();
    }

    /**
     * Restore the mandatory self-reference closure row when drifted production data removed it.
     */
    private function ensureSelfClosureExists(): void
    {
        OrganizationalUnitClosure::firstOrCreate(
            [
                'ancestor_id' => $this->id,
                'descendant_id' => $this->id,
            ],
            [
                'depth' => 0,
            ]
        );
    }

    /**
     * Check if this unit is an ancestor of the given unit.
     */
    public function isAncestorOf(OrganizationalUnit $unit): bool
    {
        if ($this->id === $unit->id) {
            return false; // Self is not an ancestor
        }

        return OrganizationalUnitClosure::where('ancestor_id', $this->id)
            ->where('descendant_id', $unit->id)
            ->where('depth', '>', 0)
            ->exists();
    }

    /**
     * Check if this unit is a descendant of the given unit.
     */
    public function isDescendantOf(OrganizationalUnit $unit): bool
    {
        if ($this->id === $unit->id) {
            return false; // Self is not a descendant
        }

        return OrganizationalUnitClosure::where('ancestor_id', $unit->id)
            ->where('descendant_id', $this->id)
            ->where('depth', '>', 0)
            ->exists();
    }

    /**
     * Get the depth of this unit from the root.
     *
     * Root units have depth 0, their direct children have depth 1, etc.
     */
    public function getDepth(): int
    {
        /** @var int|null $maxDepth */
        $maxDepth = OrganizationalUnitClosure::where('descendant_id', $this->id)
            ->where('depth', '>', 0)
            ->max('depth');

        return $maxDepth ?? 0;
    }

    /**
     * Scope to get only root organizational units (no parent).
     *
     * @param  Builder<OrganizationalUnit>  $query
     * @return Builder<OrganizationalUnit>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNotExists(function (\Illuminate\Database\Query\Builder $subquery): void {
            $subquery->select('descendant_id')
                ->from('organizational_unit_closures')
                ->whereColumn('organizational_unit_closures.descendant_id', 'organizational_units.id')
                ->where('depth', '>', 0);
        });
    }

    /**
     * Get organizational unit scopes (RBAC) for this unit.
     *
     * @return HasMany<UserInternalOrganizationalScope, $this>
     */
    public function userScopes(): HasMany
    {
        return $this->hasMany(UserInternalOrganizationalScope::class, 'organizational_unit_id');
    }
}
