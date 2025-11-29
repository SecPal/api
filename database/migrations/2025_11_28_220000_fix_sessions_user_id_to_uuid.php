<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fix sessions.user_id to support UUID after users.id was converted to UUID.
     * This was missing from the original convert_users_id_to_uuid migration.
     */
    public function up(): void
    {
        if (Schema::hasTable('sessions')) {
            // Convert sessions.user_id from bigint to varchar(36) for UUID support
            // Note: Using varchar(36) because this was already applied to production.
            // For new deployments, uuid type would be preferred for consistency with users.id
            DB::statement('ALTER TABLE sessions ALTER COLUMN user_id TYPE varchar(36) USING user_id::varchar(36)');
        }
    }

    /**
     * Reverse the migrations.
     *
     * Warning: Rollback will use placeholder value 0 for existing session user IDs.
     * This is only safe for development/testing. Production rollback requires manual cleanup.
     */
    public function down(): void
    {
        if (Schema::hasTable('sessions')) {
            // Convert back to bigint using 0 as placeholder (similar to convert_users_id_to_uuid migration)
            // Nullable column: preserve NULL values, use 0 for non-null UUIDs
            DB::statement('ALTER TABLE sessions ALTER COLUMN user_id TYPE bigint USING CASE WHEN user_id IS NULL THEN NULL ELSE 0 END');
        }
    }
};
