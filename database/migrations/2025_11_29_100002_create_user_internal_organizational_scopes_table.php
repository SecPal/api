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
 * Create user_internal_organizational_scopes table.
 *
 * This table extends the existing Spatie Permission RBAC by adding
 * organizational scope constraints. A user can have specific access
 * levels to organizational units, optionally including all descendants.
 *
 * Example: User A has "read" access to Unit X with include_descendants=true
 * => User A can read Unit X and all units in X's subtree.
 *
 * Access levels (from least to most privileged):
 * - none: No access (explicit denial)
 * - read: Can view the unit and its data
 * - write: Can modify data within the unit
 * - manage: Can manage the unit structure
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
        Schema::create('user_internal_organizational_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreignUuid('organizational_unit_id')
                ->references('id')->on('organizational_units')
                ->cascadeOnDelete();

            // Access level for this user-unit pair
            $table->enum('access_level', ['none', 'read', 'write', 'manage'])->default('read');
            $table->boolean('include_descendants')->default(false);

            // Unique constraint: One scope entry per user-unit pair
            $table->unique(['user_id', 'organizational_unit_id'], 'user_org_unit_unique');

            $table->timestamps();

            // Indexes for common queries
            $table->index('access_level');
            $table->index(['user_id', 'access_level']); // "What can this user access?"
            $table->index(['organizational_unit_id', 'access_level']); // "Who can access this unit?"

            $table->comment('User-level organizational scope assignments for RBAC');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_internal_organizational_scopes');
    }
};
