<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create the customer_closures table implementing the Closure Table Pattern.
 *
 * This table enables efficient hierarchical queries for customer organizations
 * with unlimited depth. The pattern pre-computes all ancestor-descendant
 * relationships for O(1) query performance.
 *
 * Key design decisions:
 * - Composite primary key on (ancestor_id, descendant_id) prevents duplicates
 * - Self-referencing rows with depth=0 represent each node's identity
 * - Cascading deletes via foreign keys maintain referential integrity
 * - PostgreSQL CHECK constraint prevents cycles (except self-reference)
 * - No soft deletes - closure entries are derived data, not primary entities
 *
 * Query examples:
 * - All descendants of customer X: SELECT * FROM customer_closures WHERE ancestor_id = X AND depth > 0
 * - All ancestors of customer Y: SELECT * FROM customer_closures WHERE descendant_id = Y AND depth > 0
 * - Direct children only: SELECT * FROM customer_closures WHERE ancestor_id = X AND depth = 1
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
        Schema::create('customer_closures', function (Blueprint $table): void {
            // Composite primary key prevents duplicate ancestor-descendant pairs
            $table->foreignUuid('ancestor_id')
                ->references('id')->on('customers')
                ->cascadeOnDelete();
            $table->foreignUuid('descendant_id')
                ->references('id')->on('customers')
                ->cascadeOnDelete();

            $table->primary(['ancestor_id', 'descendant_id'], 'pk_customer_closures');

            // Depth: 0 = self-reference, 1 = direct child, 2+ = deeper descendants
            $table->unsignedInteger('depth');

            // Note: Closure tables typically don't need timestamps as entries
            // are derived data managed by the application layer

            // Optimize hierarchical queries
            $table->index('depth');
            $table->index(['ancestor_id', 'depth'], 'idx_customer_closures_ancestor_depth');
            $table->index(['descendant_id', 'depth'], 'idx_customer_closures_descendant_depth');

            $table->comment('Closure table for customer hierarchy - enables O(1) descendant queries');
        });

        // PostgreSQL-specific: Add CHECK constraint to prevent cycles
        // Self-references (depth=0) are allowed, but ancestor != descendant for depth > 0
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE customer_closures
                ADD CONSTRAINT check_customer_closures_no_cycles
                CHECK (ancestor_id != descendant_id OR depth = 0)
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_closures');
    }
};
