<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove unique constraint from user_internal_organizational_scopes table.
 *
 * The original unique constraint (user_id, organizational_unit_id) was too restrictive
 * for Leadership Levels implementation (ADR-009).
 *
 * Why this change is needed:
 * - To see ALL employees (leadership + non-leadership), user needs TWO scopes:
 *   1. Scope with max_viewable_rank = 0 (for non-leadership employees)
 *   2. Scope with max_viewable_rank = 255 (for all leadership levels)
 * - Original constraint prevented multiple scopes per user/unit combination
 * - This blocked the "all employees" use case described in Issue #399 and #425
 *
 * Security implications:
 * - Still protected by tenant isolation (tenant_id FK constraint)
 * - Still protected by permission checks (Spatie RBAC)
 * - Multiple scopes are additive (OR condition), not a security risk
 *
 * @see https://github.com/SecPal/api/issues/399 Epic: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/425 Sub-Issue: Employee Policy Integration
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251221-inheritance-blocking-and-leadership-access-control.md
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_internal_organizational_scopes', function (Blueprint $table) {
            // Drop the unique constraint that prevents multiple scopes per user/unit
            $table->dropUnique('user_org_unit_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_internal_organizational_scopes', function (Blueprint $table) {
            // Re-add the constraint if rolling back
            $table->unique(['user_id', 'organizational_unit_id'], 'user_org_unit_unique');
        });
    }
};
