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
            // Using varchar instead of uuid type to avoid foreign key issues
            DB::statement('ALTER TABLE sessions ALTER COLUMN user_id TYPE varchar(36) USING user_id::varchar(36)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sessions')) {
            // Note: This will fail if there are actual UUIDs in the column
            // Only safe to run on empty or development databases
            DB::statement('ALTER TABLE sessions ALTER COLUMN user_id TYPE bigint USING NULL');
        }
    }
};
