<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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
     *
     * This migration is intentionally non-reversible: the up() step normalised
     * all 'admin' rows to 'manage' and there is no way to distinguish which
     * 'manage' rows were originally 'admin'. Attempting to roll back would
     * silently leave the data in an inconsistent state.
     *
     * @throws RuntimeException always, to prevent silent data loss on rollback
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Migration '.self::class.' cannot be rolled back: '
            .'the admin→manage normalisation is irreversible. '
            .'Restore from a pre-migration backup if a rollback is required.'
        );
    }
};
