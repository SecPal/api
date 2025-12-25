<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add leadership rank filters to user_internal_organizational_scopes table.
 *
 * This migration adds min_viewable_rank and max_viewable_rank columns to enable
 * rank-based filtering for hierarchical access control (ADR-009).
 *
 * Use cases:
 * - Area Manager (rank 5) can view employees with rank >= 6 (subordinates only)
 * - Branch Director (rank 3) can view employees with rank >= 4 (all below, not peers)
 * - NULL values = no rank filtering (see all ranks)
 *
 * Filtering logic:
 * - min_viewable_rank: Minimum rank user can view (inclusive)
 * - max_viewable_rank: Maximum rank user can view (inclusive)
 * - Both NULL = no filtering
 * - Filters work WITH organizational scopes (AND condition)
 *
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251221-inheritance-blocking-and-leadership-access-control.md
 * @see https://github.com/SecPal/api/issues/399 Epic: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/423 Sub-Issue: Database Migrations
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_internal_organizational_scopes', function (Blueprint $table) {
            // Minimum viewable rank (inclusive)
            // Example: If user has rank 3 and min_viewable_rank=4, they see ranks 4, 5, 6, ...
            $table->unsignedTinyInteger('min_viewable_rank')
                ->nullable()
                ->after('include_descendants')
                ->comment('Minimum leadership rank user can view (NULL = no minimum filter)');

            // Maximum viewable rank (inclusive)
            // Example: If max_viewable_rank=10, user sees ranks up to 10 (not 11+)
            $table->unsignedTinyInteger('max_viewable_rank')
                ->nullable()
                ->after('min_viewable_rank')
                ->comment('Maximum leadership rank user can view (NULL = no maximum filter)');

            // Index for common query: "Get accessible employees within rank range"
            $table->index(
                ['user_id', 'min_viewable_rank', 'max_viewable_rank'],
                'user_org_scopes_user_rank_filters_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_internal_organizational_scopes', function (Blueprint $table) {
            $table->dropIndex('user_org_scopes_user_rank_filters_idx');
            $table->dropColumn(['min_viewable_rank', 'max_viewable_rank']);
        });
    }
};
