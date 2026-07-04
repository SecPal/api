<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create organizational_unit_closures table (Closure Table Pattern).
 *
 * This table enables efficient hierarchical queries with O(1) complexity
 * for "all descendants" or "all ancestors" operations.
 *
 * Key properties:
 * - Unlimited depth (depth is an unsigned integer, not constrained)
 * - Fast queries: "All descendants of X" = WHERE ancestor_id = X
 * - Self-reference: Every unit has entry with depth=0 (ancestor=descendant=self)
 * - Path independence: No need to store/traverse paths
 *
 * Note on Soft Deletes:
 * The cascadeOnDelete() only triggers on hard deletes. Soft-deleted organizational
 * units retain their closure table entries, allowing restoration with intact
 * relationships. Queries traversing hierarchies must join with
 * whereNull('organizational_units.deleted_at') to exclude soft-deleted units.
 *
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251126-organizational-structure-hierarchy.md
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organizational_unit_closures', function (Blueprint $table) {
            $table->foreignUuid('ancestor_id')
                ->references('id')->on('organizational_units')
                ->cascadeOnDelete();
            $table->foreignUuid('descendant_id')
                ->references('id')->on('organizational_units')
                ->cascadeOnDelete();

            // Depth: 0=self, 1=direct child, 2=grandchild, etc. (never negative)
            $table->unsignedInteger('depth');

            // Composite primary key
            $table->primary(['ancestor_id', 'descendant_id']);

            // Indexes for efficient hierarchical queries
            $table->index('depth');
            $table->index(['ancestor_id', 'depth']); // Fast "get all descendants" queries
            $table->index(['descendant_id', 'depth']); // Fast "get all ancestors" queries

            $table->comment('Closure table for organizational unit hierarchies (O(1) descendant queries)');
        });

        // PostgreSQL CHECK constraint to prevent invalid cycles
        // (ancestor=descendant is only valid for depth=0, i.e., self-reference)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE organizational_unit_closures
                ADD CONSTRAINT check_valid_self_reference
                CHECK (ancestor_id != descendant_id OR depth = 0)
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizational_unit_closures');
    }
};
