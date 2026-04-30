<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('user_internal_organizational_scopes')
            ->where('access_level', 'admin')
            ->update(['access_level' => 'manage']);

        DB::statement('ALTER TABLE user_internal_organizational_scopes DROP CONSTRAINT IF EXISTS user_internal_organizational_scopes_access_level_check');
        DB::statement(<<<'SQL'
            ALTER TABLE user_internal_organizational_scopes
            ADD CONSTRAINT user_internal_organizational_scopes_access_level_check
            CHECK (access_level IN ('none', 'read', 'write', 'manage'))
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE user_internal_organizational_scopes DROP CONSTRAINT IF EXISTS user_internal_organizational_scopes_access_level_check');
        DB::statement(<<<'SQL'
            ALTER TABLE user_internal_organizational_scopes
            ADD CONSTRAINT user_internal_organizational_scopes_access_level_check
            CHECK (access_level IN ('none', 'read', 'write', 'manage', 'admin'))
        SQL);
    }
};
