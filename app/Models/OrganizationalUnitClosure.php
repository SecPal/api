<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrganizationalUnitClosure model for the Closure Table Pattern.
 *
 * Represents pre-computed ancestor-descendant relationships in the organizational
 * hierarchy. Each row represents one ancestor-descendant pair with the depth
 * (number of edges) between them.
 *
 * Key Properties:
 * - Self-references (depth=0): Every unit has an entry where ancestor_id = descendant_id
 * - Direct parent (depth=1): Parent-child relationships
 * - Transitive ancestors (depth>1): Grandparents, great-grandparents, etc.
 *
 * @property string $ancestor_id UUID of the ancestor organizational unit
 * @property string $descendant_id UUID of the descendant organizational unit
 * @property int $depth Number of edges between ancestor and descendant (0=self)
 * @property-read OrganizationalUnit $ancestor The ancestor organizational unit
 * @property-read OrganizationalUnit $descendant The descendant organizational unit
 */
class OrganizationalUnitClosure extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'organizational_unit_closures';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Indicates if the IDs are auto-incrementing.
     * This table uses composite primary key, not UUID generation.
     *
     * @var bool
     */
    public $incrementing = false;

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
     * Override uniqueIds to prevent UUID generation for this composite key table.
     *
     * @return array<string>
     */
    public function uniqueIds(): array
    {
        return []; // No UUID columns to auto-generate
    }

    /**
     * Get the ancestor organizational unit.
     *
     * @return BelongsTo<OrganizationalUnit, $this>
     */
    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'ancestor_id');
    }

    /**
     * Get the descendant organizational unit.
     *
     * @return BelongsTo<OrganizationalUnit, $this>
     */
    public function descendant(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'descendant_id');
    }
}
