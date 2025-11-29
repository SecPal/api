<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Customer model representing external customer organizations.
 *
 * This model represents customer hierarchies which are COMPLETELY SEPARATE from
 * internal organizational units. Customers are external organizations that the
 * security service company manages.
 *
 * Uses the Closure Table Pattern for efficient hierarchical queries,
 * implemented identically to OrganizationalUnit.
 *
 * Key Features:
 * - UUID primary key for distributed ID generation
 * - Soft deletes for data preservation
 * - Automatic closure table management for hierarchy
 * - Unlimited hierarchy depth support
 * - Read-only access for customer users (Client role)
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to tenant_keys
 * @property string|null $managed_by_organizational_unit_id Internal org unit managing this customer
 * @property string $name Display name of the customer
 * @property string $customer_number Unique customer identifier
 * @property string $type Enum: corporate, regional, local, custom
 * @property string|null $address Business address
 * @property string|null $contact_email Primary contact email
 * @property string|null $contact_phone Primary contact phone
 * @property array<string, mixed>|null $metadata JSON metadata (industry, contract_start, etc.)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read TenantKey $tenant The tenant this customer belongs to
 * @property-read OrganizationalUnit|null $managedBy The internal org unit managing this customer
 * @property-read Customer|null $parent The direct parent customer
 * @property-read Collection<int, Customer> $children Direct child customers
 * @property-read Collection<int, Customer> $ancestors All ancestor customers (ordered by depth, closest first)
 * @property-read Collection<int, Customer> $descendants All descendant customers
 * @property-read Collection<int, SecPalObject> $objects All objects belonging to this customer
 */
class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory, HasUuids, SoftDeletes;

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
        'managed_by_organizational_unit_id',
        'name',
        'customer_number',
        'type',
        'address',
        'contact_email',
        'contact_phone',
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
            'metadata' => 'array',
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
        // Create self-reference closure entry when customer is created
        static::created(function (Customer $customer): void {
            CustomerClosure::create([
                'ancestor_id' => $customer->id,
                'descendant_id' => $customer->id,
                'depth' => 0,
            ]);
        });

        // Note: Closure table cleanup on force delete is handled by ON DELETE CASCADE
        // in the database migration, so no explicit handler is needed here.
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
     * Get the internal organizational unit that manages this customer.
     *
     * This is for INTERNAL use only - customer users do NOT see this relationship!
     *
     * @return BelongsTo<OrganizationalUnit, $this>
     */
    public function managedBy(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'managed_by_organizational_unit_id');
    }

    /**
     * Get the direct parent customer.
     *
     * Uses the closure table to find the ancestor at depth=1.
     *
     * WARNING: This accessor executes a query on each access (N+1 potential).
     * For batch operations, use ancestors() with eager loading instead.
     */
    public function getParentAttribute(): ?Customer
    {
        /** @var string|null $parentId */
        $parentId = CustomerClosure::where('descendant_id', $this->id)
            ->where('depth', 1)
            ->value('ancestor_id');

        if ($parentId === null) {
            return null;
        }

        /** @var Customer|null */
        return Customer::find($parentId);
    }

    /**
     * Get all direct children of this customer.
     *
     * WARNING: This accessor executes a query on each access (N+1 potential).
     * For batch operations, use descendants() with depth=1 filter instead.
     *
     * @return Collection<int, Customer>
     */
    public function getChildrenAttribute(): Collection
    {
        $childIds = CustomerClosure::where('ancestor_id', $this->id)
            ->where('depth', 1)
            ->pluck('descendant_id');

        return Customer::whereIn('id', $childIds)->get();
    }

    /**
     * Get all ancestors of this customer.
     *
     * Returns ancestors ordered by depth (closest first: parent, grandparent, etc.)
     *
     * @return BelongsToMany<Customer, $this>
     */
    public function ancestors(): BelongsToMany
    {
        return $this->belongsToMany(
            Customer::class,
            'customer_closures',
            'descendant_id',
            'ancestor_id'
        )
            ->wherePivot('depth', '>', 0)
            ->orderByPivot('depth', 'asc')
            ->withPivot('depth');
    }

    /**
     * Get all descendants of this customer.
     *
     * @return BelongsToMany<Customer, $this>
     */
    public function descendants(): BelongsToMany
    {
        return $this->belongsToMany(
            Customer::class,
            'customer_closures',
            'ancestor_id',
            'descendant_id'
        )
            ->wherePivot('depth', '>', 0)
            ->orderByPivot('depth', 'asc')
            ->withPivot('depth');
    }

    /**
     * Get all objects belonging to this customer.
     *
     * @return HasMany<SecPalObject, $this>
     */
    public function objects(): HasMany
    {
        return $this->hasMany(SecPalObject::class, 'customer_id');
    }

    /**
     * Get customer user access records for this customer.
     *
     * @return HasMany<CustomerUserAccess, $this>
     */
    public function userAccesses(): HasMany
    {
        return $this->hasMany(CustomerUserAccess::class, 'customer_id');
    }

    /**
     * Set the parent of this customer.
     *
     * Updates the closure table to reflect the new hierarchy position.
     * If moving from an existing parent, old closure entries are removed first.
     * Wrapped in a database transaction to ensure data consistency.
     *
     * @throws \InvalidArgumentException If setting would create a cycle
     */
    public function setParent(?Customer $parent): void
    {
        if ($parent === null) {
            $this->removeParent();

            return;
        }

        // Prevent cross-tenant hierarchy links (security)
        if ($parent->tenant_id !== $this->tenant_id) {
            throw new \InvalidArgumentException('Cannot set parent from a different tenant.');
        }

        // Prevent setting self as parent (cycle prevention)
        if ($parent->id === $this->id) {
            throw new \InvalidArgumentException('Cannot set customer as its own parent.');
        }

        // Get all descendants of this customer (including self) - needed for cycle check
        /** @var list<string> $descendantIds */
        $descendantIds = CustomerClosure::where('ancestor_id', $this->id)
            ->pluck('descendant_id')
            ->all();

        // Prevent setting a descendant as parent (cycle prevention)
        if (in_array($parent->id, $descendantIds, true)) {
            throw new \InvalidArgumentException('Cannot set a descendant as parent (would create a cycle).');
        }

        // Wrap in transaction for data consistency
        DB::transaction(function () use ($parent, $descendantIds): void {
            // Remove old ancestor entries (if any) for this customer and all descendants
            $this->removeAncestorClosures();

            // Fetch all depths from this customer to its descendants in one query (N+1 fix)
            /** @var array<string, int> $descendantDepths */
            $descendantDepths = CustomerClosure::where('ancestor_id', $this->id)
                ->whereIn('descendant_id', $descendantIds)
                ->pluck('depth', 'descendant_id')
                ->all();

            // Get all ancestors of new parent (including parent itself via depth+1)
            $parentAncestors = CustomerClosure::where('descendant_id', $parent->id)
                ->get();

            // Create new closure entries: each ancestor of parent -> each descendant of this customer
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
                CustomerClosure::insert($newClosures);
            }
        });
    }

    /**
     * Remove the parent relationship, making this customer a root.
     *
     * Removes all ancestor closures for this customer and its descendants,
     * keeping only internal subtree relationships.
     */
    public function removeParent(): void
    {
        $this->removeAncestorClosures();
    }

    /**
     * Remove all ancestor closure entries for this customer and its descendants.
     *
     * Preserves the internal subtree relationships (within descendants).
     */
    private function removeAncestorClosures(): void
    {
        // Get all descendant IDs (including self)
        $descendantIds = CustomerClosure::where('ancestor_id', $this->id)
            ->pluck('descendant_id')
            ->toArray();

        // Delete closures where:
        // - descendant is in our subtree AND
        // - ancestor is NOT in our subtree (i.e., it's an external ancestor)
        CustomerClosure::whereIn('descendant_id', $descendantIds)
            ->whereNotIn('ancestor_id', $descendantIds)
            ->delete();
    }

    /**
     * Check if this customer is an ancestor of the given customer.
     */
    public function isAncestorOf(Customer $customer): bool
    {
        if ($this->id === $customer->id) {
            return false; // Self is not an ancestor
        }

        return CustomerClosure::where('ancestor_id', $this->id)
            ->where('descendant_id', $customer->id)
            ->where('depth', '>', 0)
            ->exists();
    }

    /**
     * Check if this customer is a descendant of the given customer.
     */
    public function isDescendantOf(Customer $customer): bool
    {
        if ($this->id === $customer->id) {
            return false; // Self is not a descendant
        }

        return CustomerClosure::where('ancestor_id', $customer->id)
            ->where('descendant_id', $this->id)
            ->where('depth', '>', 0)
            ->exists();
    }

    /**
     * Get the depth of this customer from the root.
     *
     * Root customers have depth 0, their direct children have depth 1, etc.
     */
    public function getDepth(): int
    {
        /** @var int|null $maxDepth */
        $maxDepth = CustomerClosure::where('descendant_id', $this->id)
            ->where('depth', '>', 0)
            ->max('depth');

        return $maxDepth ?? 0;
    }

    /**
     * Scope to get only root customers (no parent).
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNotExists(function (\Illuminate\Database\Query\Builder $subquery): void {
            $subquery->select('descendant_id')
                ->from('customer_closures')
                ->whereColumn('customer_closures.descendant_id', 'customers.id')
                ->where('depth', '>', 0);
        });
    }

    /**
     * Scope to filter by customer type.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter by managing organizational unit.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeManagedBy(Builder $query, OrganizationalUnit $unit): Builder
    {
        return $query->where('managed_by_organizational_unit_id', $unit->id);
    }
}
