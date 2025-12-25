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
 * Create leadership_levels table for tenant-configurable leadership hierarchies.
 *
 * This table stores tenant-specific leadership level definitions used for
 * hierarchical access control (ADR-009: Leadership-Based Access Control).
 *
 * Leadership levels enable:
 * - Hierarchical employee visibility (subordinates only, not peers/superiors)
 * - Tenant-configurable ranks (e.g., CEO=1, Branch Director=2, Site Manager=3)
 * - Command chain and escalation paths (BewachV § 9 compliance)
 * - Permission assignment with rank-based filtering
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
        Schema::create('leadership_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();

            // Numerical hierarchy rank (1=highest, ascending for lower levels)
            // Example: CEO=1, Regional Director=2, Branch Director=3, Site Manager=4
            $table->unsignedTinyInteger('rank')
                ->comment('Numerical hierarchy (1=CEO/highest, ascending for lower levels)');

            // Display name for this leadership level
            // Example: "Managing Director", "Niederlassungsleiter", "Site Manager"
            $table->string('name', 100)
                ->comment('Display name (e.g., "Managing Director", "Site Manager")');

            // Optional description explaining this level's responsibilities
            $table->text('description')->nullable();

            // Optional hex color for UI visualization (e.g., "#FF5733")
            $table->string('color', 7)->nullable()->comment('Hex color for UI (e.g., "#FF5733")');

            // Soft delete flag to deactivate levels without losing history
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Ensure rank uniqueness per tenant (no duplicate ranks)
            $table->unique(['tenant_id', 'rank'], 'leadership_levels_tenant_rank_unique');

            // Ensure name uniqueness per tenant (no duplicate names)
            $table->unique(['tenant_id', 'name'], 'leadership_levels_tenant_name_unique');

            // Index for common queries (active levels for a tenant)
            $table->index(['tenant_id', 'is_active', 'rank'], 'leadership_levels_tenant_active_rank_idx');

            $table->comment('Tenant-configurable leadership hierarchy definitions for hierarchical access control');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leadership_levels');
    }
};
