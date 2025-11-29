<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * - admin: Full administrative access
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
        // Create enum type for PostgreSQL
        DB::statement("
            DO $$ BEGIN
                CREATE TYPE user_scope_access_level AS ENUM ('none', 'read', 'write', 'manage', 'admin');
            EXCEPTION
                WHEN duplicate_object THEN null;
            END $$;
        ");

        Schema::create('user_internal_organizational_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreignUuid('organizational_unit_id')
                ->references('id')->on('organizational_units')
                ->cascadeOnDelete();

            // Unique constraint: One scope entry per user-unit pair
            $table->unique(['user_id', 'organizational_unit_id'], 'user_org_unit_unique');

            $table->timestamps();
        });

        // Add access_level enum column for PostgreSQL
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE user_internal_organizational_scopes
                ADD COLUMN access_level user_scope_access_level NOT NULL DEFAULT 'read'
            ");
            DB::statement('
                ALTER TABLE user_internal_organizational_scopes
                ADD COLUMN include_descendants BOOLEAN NOT NULL DEFAULT FALSE
            ');
        } else {
            // Fallback for SQLite (testing) or other databases
            Schema::table('user_internal_organizational_scopes', function (Blueprint $table) {
                $table->string('access_level')->default('read');
                $table->boolean('include_descendants')->default(false);
            });
        }

        // Add indexes for common queries
        Schema::table('user_internal_organizational_scopes', function (Blueprint $table) {
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

        // Drop enum type if PostgreSQL
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TYPE IF EXISTS user_scope_access_level');
        }
    }
};
