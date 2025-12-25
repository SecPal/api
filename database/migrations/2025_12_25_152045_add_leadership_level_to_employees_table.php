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
 * Add leadership_level_id foreign key to employees table.
 *
 * This column links employees to their leadership level (if any) for hierarchical
 * access control. Most employees will have NULL (no leadership position).
 *
 * Leadership assignment rules (ADR-009):
 * - Only employees with leadership roles get a leadership_level_id
 * - Guards, regular staff, etc. have NULL leadership_level_id
 * - Used for hierarchical visibility (can only see employees with lower rank)
 * - NOT a permission grant (permissions are separate via Spatie)
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
        Schema::table('employees', function (Blueprint $table) {
            // Foreign key to leadership_levels table (nullable - most employees have no leadership role)
            // Leadership level assignment (NULL for non-leadership employees)
            $table->foreignUuid('leadership_level_id')
                ->nullable()
                ->after('organizational_unit_id')
                ->constrained('leadership_levels')
                ->nullOnDelete();

            // Index for common query: "Get all employees below rank X"
            $table->index(['tenant_id', 'leadership_level_id'], 'employees_tenant_leadership_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Drop foreign key and index
            $table->dropForeign(['leadership_level_id']);
            $table->dropIndex('employees_tenant_leadership_idx');
            $table->dropColumn('leadership_level_id');
        });
    }
};
