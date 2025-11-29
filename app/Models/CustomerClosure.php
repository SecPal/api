<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CustomerClosure model representing the closure table for customer hierarchies.
 *
 * This is an internal model used to implement the Closure Table Pattern
 * for efficient hierarchical queries on customer relationships.
 *
 * The closure table stores all ancestor-descendant relationships with their
 * depths, enabling O(1) queries for "all descendants" and "all ancestors".
 *
 * Key concepts:
 * - Self-references (depth=0): Every customer has a row where ancestor=descendant
 * - Direct parent (depth=1): Immediate parent-child relationship
 * - Indirect ancestors (depth>1): Grandparents, great-grandparents, etc.
 *
 * @property string $ancestor_id UUID of the ancestor customer
 * @property string $descendant_id UUID of the descendant customer
 * @property int $depth Distance from ancestor to descendant (0=self, 1=direct child, etc.)
 * @property-read Customer $ancestor The ancestor customer
 * @property-read Customer $descendant The descendant customer
 */
class CustomerClosure extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'customer_closures';

    /**
     * Indicates if the model should be timestamped.
     *
     * Closure table entries are derived data managed by the application layer,
     * not user-created entities, so timestamps are not needed.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * This table uses a composite primary key, not auto-increment.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The primary key for the model.
     *
     * Note: Laravel doesn't natively support composite primary keys,
     * but the database enforces the (ancestor_id, descendant_id) constraint.
     * Setting to empty string disables auto-incrementing key behavior.
     */
    protected $primaryKey = '';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ancestor_id',
        'descendant_id',
        'depth',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
        ];
    }

    /**
     * Get the ancestor customer.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'ancestor_id');
    }

    /**
     * Get the descendant customer.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function descendant(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'descendant_id');
    }
}
