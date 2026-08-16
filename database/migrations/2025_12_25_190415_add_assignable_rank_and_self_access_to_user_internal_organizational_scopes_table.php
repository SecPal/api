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
 * Add assignable rank filters and self-access control to user_internal_organizational_scopes table.
 *
 * This migration completes the Leadership Levels implementation (ADR-009) by adding:
 * 1. min/max_assignable_rank: Control which leadership levels user can assign/remove
 * 2. allow_self_access: Prevent users from viewing/editing own HR data
 *
 * Security Rationale:
 * - Permission Escalation Prevention: User must have permission to ASSIGN a rank to REMOVE it
 * - Self-Manipulation Prevention: Users cannot edit own salary, leadership level by default
 *
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251221-inheritance-blocking-and-leadership-access-control.md
 * @see https://github.com/SecPal/api/issues/399 Epic: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/423 Sub-Issue: Database Foundation
 * @see https://github.com/SecPal/api/issues/425 Sub-Issue: Employee Policy Integration
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_internal_organizational_scopes', function (Blueprint $table) {
            // Minimum assignable rank (inclusive)
            // Controls which leadership levels user can assign/remove
            // Example: If min_assignable_rank=5, user can assign/remove FE5 and below
            $table->unsignedTinyInteger('min_assignable_rank')
                ->nullable()
                ->after('max_viewable_rank')
                ->comment('Minimum leadership rank user can assign/remove (NULL = no minimum filter)');

            // Maximum assignable rank (inclusive)
            // NULL or 0 = CANNOT assign OR remove ANY leadership level
            // Example: If max_assignable_rank=5, user can only assign/remove FE5-FE255
            // SECURITY: To REMOVE FE from employee, must have permission to ASSIGN that FE
            $table->unsignedTinyInteger('max_assignable_rank')
                ->nullable()
                ->after('min_assignable_rank')
                ->comment('Maximum leadership rank user can assign/remove (NULL/0 = cannot assign/remove ANY leadership)');

            // Self-access control
            // Default: false - users cannot view/edit own HR data
            // Requires explicit true to allow self-access (e.g., for HR managers)
            // Prevents users from manipulating own salary, leadership level, etc.
            $table->boolean('allow_self_access')
                ->default(false)
                ->after('max_assignable_rank')
                ->comment('Allow user to view/edit own employee HR data (default: false for security)');

            // Index for common query: "Get assignable rank range for user"
            $table->index(
                ['user_id', 'min_assignable_rank', 'max_assignable_rank'],
                'user_org_scopes_user_assignable_rank_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_internal_organizational_scopes', function (Blueprint $table) {
            $table->dropIndex('user_org_scopes_user_assignable_rank_idx');
            $table->dropColumn(['min_assignable_rank', 'max_assignable_rank', 'allow_self_access']);
        });
    }
};
